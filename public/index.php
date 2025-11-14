<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/src/Database.php';
require_once $root . '/src/Link.php';
require_once $root . '/src/Router.php';

use App\Database;
use App\Link;
use App\Router;

// Load config. A local override file takes precedence if it exists.
$config = (static function (string $root): array {
    $local = $root . '/config.local.php';
    $base  = require $root . '/config.php';
    return file_exists($local) ? array_merge($base, require $local) : $base;
})($root);

// Bootstrap dependencies.
$db     = new Database($config['db_path']);
$links  = new Link($db);
$router = new Router();

// Resolve base URL: config value wins, otherwise derive from the request.
$baseUrl = rtrim($config['base_url'] ?? (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
), '/');

// ─── Web Routes ──────────────────────────────────────────────────────────────

$router->get('/', function () use ($links, $baseUrl) {
    $all   = $links->all();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    echo renderHome($all, $baseUrl, $flash);
});

$router->post('/', function () use ($links, $baseUrl, $config) {
    $url = trim($_POST['url'] ?? '');

    if ($url === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'URL cannot be empty.'];
        header('Location: /');
        exit;
    }

    try {
        $code = $links->create($url, $config['code_length']);
        $_SESSION['flash'] = [
            'type'  => 'success',
            'short' => $baseUrl . '/' . $code,
        ];
    } catch (\InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }

    header('Location: /');
    exit;
});

$router->get('/{code}', function (array $params) use ($links) {
    $url = $links->resolve($params['code']);

    if ($url === null) {
        http_response_code(404);
        echo '<p style="font-family:monospace;color:#cdd6f4;background:#1e1e2e;padding:2rem;">Short link not found.</p>';
        return;
    }

    header('Location: ' . $url, true, 301);
    exit;
});

// ─── API Routes ──────────────────────────────────────────────────────────────

/**
 * POST /api/shorten
 * Body (JSON): { "url": "https://..." }
 * Returns 201 with the created link object.
 */
$router->post('/api/shorten', function () use ($links, $baseUrl, $config) {
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $url  = trim($body['url'] ?? '');

    if ($url === '') {
        http_response_code(422);
        echo json_encode(['error' => 'url is required.']);
        return;
    }

    try {
        $code = $links->create($url, $config['api_code_length']);
        $link = $links->find($code);

        http_response_code(201);
        echo json_encode([
            'code'       => $link['code'],
            'short'      => $baseUrl . '/' . $link['code'],
            'url'        => $link['url'],
            'clicks'     => (int) $link['clicks'],
            'created_at' => $link['created_at'],
        ]);
    } catch (\InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

/**
 * GET /api/links
 * Returns all links as a JSON array.
 */
$router->get('/api/links', function () use ($links, $baseUrl) {
    header('Content-Type: application/json');

    $all = array_map(fn(array $link): array => [
        'code'       => $link['code'],
        'short'      => $baseUrl . '/' . $link['code'],
        'url'        => $link['url'],
        'clicks'     => (int) $link['clicks'],
        'created_at' => $link['created_at'],
    ], $links->all());

    echo json_encode($all);
});

/**
 * GET /api/links/{code}
 * Returns a single link by its short code.
 */
$router->get('/api/links/{code}', function (array $params) use ($links, $baseUrl) {
    header('Content-Type: application/json');

    $link = $links->find($params['code']);

    if ($link === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Link not found.']);
        return;
    }

    echo json_encode([
        'code'       => $link['code'],
        'short'      => $baseUrl . '/' . $link['code'],
        'url'        => $link['url'],
        'clicks'     => (int) $link['clicks'],
        'created_at' => $link['created_at'],
    ]);
});

// ─── Dispatch ────────────────────────────────────────────────────────────────

session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$router->dispatch($method, $path);

// ─── View ────────────────────────────────────────────────────────────────────

/**
 * Render the homepage HTML.
 *
 * @param array<int, array<string, mixed>> $all
 * @param array<string, mixed>|null        $flash
 */
function renderHome(array $all, string $baseUrl, ?array $flash): string
{
    $host      = parse_url($baseUrl, PHP_URL_HOST) ?? $baseUrl;
    $flashHtml = '';

    if ($flash !== null) {
        if ($flash['type'] === 'success') {
            $short     = htmlspecialchars($flash['short'] ?? '');
            $flashHtml = <<<HTML
            <div class="border border-[#a6e3a1] bg-[#181825] text-[#a6e3a1] px-4 py-3 rounded text-sm mb-6 flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Link created: <a href="{$short}" class="text-[#89b4fa] underline underline-offset-2" target="_blank">{$short}</a></span>
            </div>
            HTML;
        } else {
            $msg       = htmlspecialchars($flash['message'] ?? 'An error occurred.');
            $flashHtml = <<<HTML
            <div class="border border-[#f38ba8] bg-[#181825] text-[#f38ba8] px-4 py-3 rounded text-sm mb-6 flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>{$msg}</span>
            </div>
            HTML;
        }
    }

    $rows = '';
    foreach ($all as $link) {
        $code       = htmlspecialchars($link['code']);
        $url        = htmlspecialchars($link['url']);
        $short      = htmlspecialchars($baseUrl . '/' . $link['code']);
        $clicks     = (int) $link['clicks'];
        $created    = htmlspecialchars($link['created_at']);
        $displayUrl = strlen($url) > 55 ? substr($url, 0, 52) . '...' : $url;

        $rows .= <<<HTML
        <tr class="border-t border-[#313244] hover:bg-[#181825] transition-colors">
            <td class="py-3 px-4">
                <a href="{$short}" class="text-[#89b4fa] hover:underline underline-offset-2" target="_blank">/{$code}</a>
            </td>
            <td class="py-3 px-4 text-[#a6adc8] text-xs" title="{$url}">{$displayUrl}</td>
            <td class="py-3 px-4 text-[#cba6f7] text-center">{$clicks}</td>
            <td class="py-3 px-4 text-[#a6adc8] text-xs">{$created}</td>
        </tr>
        HTML;
    }

    $tableOrEmpty = $rows !== ''
        ? <<<HTML
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[#a6adc8] uppercase tracking-widest text-xs">
                        <th class="py-2 px-4 text-left">Short Link</th>
                        <th class="py-2 px-4 text-left">Original URL</th>
                        <th class="py-2 px-4 text-center">Clicks</th>
                        <th class="py-2 px-4 text-left">Created</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>
        HTML
        : '<p class="text-[#a6adc8] text-sm px-4 py-3">No links yet. Create one above.</p>';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$host}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            * { font-family: 'JetBrains Mono', monospace; }
            body { background-color: #1e1e2e; color: #cdd6f4; }
        </style>
    </head>
    <body class="min-h-screen py-20 px-6">
        <div class="max-w-3xl mx-auto">

            <header class="mb-12">
                <div class="flex items-center gap-3 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#cba6f7" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                    <h1 class="text-xl font-bold text-[#cba6f7]">{$host}</h1>
                </div>
                <p class="text-[#a6adc8] text-sm">Paste a long URL. Get a short one. That's it.</p>
            </header>

            {$flashHtml}

            <form method="POST" action="/" class="mb-12">
                <div class="flex gap-3">
                    <input
                        type="url"
                        name="url"
                        placeholder="https://example.com/very/long/url"
                        required
                        class="flex-1 bg-[#181825] border border-[#313244] text-[#cdd6f4] placeholder-[#585b70] rounded px-4 py-2 text-sm focus:outline-none focus:border-[#cba6f7] transition-colors"
                    >
                    <button
                        type="submit"
                        class="bg-[#cba6f7] text-[#1e1e2e] font-bold px-5 py-2 rounded text-sm hover:opacity-90 transition-opacity flex items-center gap-2"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Shorten
                    </button>
                </div>
            </form>

            <section>
                <h2 class="text-xs uppercase tracking-widest text-[#a6adc8] mb-4">All Links</h2>
                <div class="border border-[#313244] rounded">
                    {$tableOrEmpty}
                </div>
            </section>

            <footer class="mt-16 text-[#585b70] text-xs text-center">
                {$host}
            </footer>

        </div>
    </body>
    </html>
    HTML;
}
