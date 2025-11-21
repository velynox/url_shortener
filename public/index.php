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

$githubUrl = $config['github_url'] ?? 'https://github.com/velynox';

// ─── Web Routes ──────────────────────────────────────────────────────────────

$router->get('/', function () use ($links, $baseUrl, $githubUrl) {
    $all   = $links->all();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    echo renderHome($all, $baseUrl, $githubUrl, $flash);
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

// API docs page.
$router->get('/api', function () use ($baseUrl, $githubUrl, $config) {
    echo renderApiDocs($baseUrl, $githubUrl, $config);
});

// ─── API Routes ──────────────────────────────────────────────────────────────

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

/**
 * DELETE /api/links/{code}
 * Delete a link by its short code. Returns 204 on success, 404 if not found.
 */
$router->delete('/api/links/{code}', function (array $params) use ($links) {
    header('Content-Type: application/json');

    $deleted = $links->delete($params['code']);

    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['error' => 'Link not found.']);
        return;
    }

    http_response_code(204);
});

// Redirect short link — must be last; skip reserved paths.
$router->get('/{code}', function (array $params) use ($links) {
    // Guard against accidentally catching /api or other reserved segments.
    if (in_array($params['code'], ['api', 'favicon.ico'], true)) {
        http_response_code(404);
        return;
    }

    $url = $links->resolve($params['code']);

    if ($url === null) {
        http_response_code(404);
        echo '<p style="font-family:monospace;color:#cdd6f4;background:#1e1e2e;padding:2rem;">Short link not found.</p>';
        return;
    }

    header('Location: ' . $url, true, 301);
    exit;
});

// ─── Dispatch ────────────────────────────────────────────────────────────────

session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$router->dispatch($method, $path);

// ─── Shared layout helpers ───────────────────────────────────────────────────

/**
 * Shared <head> block and opening body wrapper.
 */
function layoutHead(string $title): string
{
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            * { font-family: 'JetBrains Mono', monospace; }
            body { background-color: #1e1e2e; color: #cdd6f4; }
            .copy-btn { cursor: pointer; }
            .copy-btn:active { opacity: 0.6; }
        </style>
    </head>
    <body class="min-h-screen py-20 px-6">
    <div class="max-w-3xl mx-auto">
    HTML;
}

/** Shared footer + closing tags. */
function layoutFoot(string $host, string $githubUrl): string
{
    $ghUrl = htmlspecialchars($githubUrl);
    return <<<HTML
        <footer class="mt-16 text-[#585b70] text-xs flex items-center justify-center gap-4">
            <span>{$host}</span>
            <span>&mdash;</span>
            <a href="{$ghUrl}" target="_blank" rel="noopener" class="flex items-center gap-1.5 hover:text-[#cdd6f4] transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
                </svg>
                github
            </a>
            <span>&mdash;</span>
            <a href="/api" class="hover:text-[#cdd6f4] transition-colors">api docs</a>
        </footer>
    </div>
    </body>
    </html>
    HTML;
}

// ─── Home view ───────────────────────────────────────────────────────────────

/**
 * @param array<int, array<string, mixed>> $all
 * @param array<string, mixed>|null        $flash
 */
function renderHome(array $all, string $baseUrl, string $githubUrl, ?array $flash): string
{
    $host      = parse_url($baseUrl, PHP_URL_HOST) ?? $baseUrl;
    $flashHtml = '';

    if ($flash !== null) {
        if ($flash['type'] === 'success') {
            $short     = htmlspecialchars($flash['short'] ?? '');
            $flashHtml = <<<HTML
            <div class="border border-[#a6e3a1] bg-[#181825] text-[#a6e3a1] px-4 py-3 rounded text-sm mb-6 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><polyline points="20 6 9 17 4 12"/></svg>
                    <span class="truncate">Link ready: <a href="{$short}" class="text-[#89b4fa] underline underline-offset-2" target="_blank">{$short}</a></span>
                </div>
                <button
                    class="copy-btn shrink-0 flex items-center gap-1.5 text-[#a6adc8] hover:text-[#cdd6f4] transition-colors text-xs border border-[#313244] rounded px-2 py-1"
                    onclick="navigator.clipboard.writeText('{$short}').then(() => { this.querySelector('span').textContent = 'copied'; setTimeout(() => this.querySelector('span').textContent = 'copy', 1500); })"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    <span>copy</span>
                </button>
            </div>
            HTML;
        } else {
            $msg       = htmlspecialchars($flash['message'] ?? 'An error occurred.');
            $flashHtml = <<<HTML
            <div class="border border-[#f38ba8] bg-[#181825] text-[#f38ba8] px-4 py-3 rounded text-sm mb-6 flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>{$msg}</span>
            </div>
            HTML;
        }
    }

    // Stats
    $totalLinks      = count($all);
    $totalClicks     = (int) array_sum(array_column($all, 'clicks'));
    $linkLabel       = $totalLinks  !== 1 ? 'links'  : 'link';
    $clickLabel      = $totalClicks !== 1 ? 'clicks' : 'click';

    $rows = '';
    foreach ($all as $link) {
        $code       = htmlspecialchars($link['code']);
        $url        = htmlspecialchars($link['url']);
        $short      = htmlspecialchars($baseUrl . '/' . $link['code']);
        $clicks     = (int) $link['clicks'];
        $created    = htmlspecialchars($link['created_at']);
        $displayUrl = strlen($url) > 52 ? substr($url, 0, 49) . '...' : $url;

        $rows .= <<<HTML
        <tr class="border-t border-[#313244] hover:bg-[#181825] transition-colors group">
            <td class="py-3 px-4">
                <div class="flex items-center gap-2">
                    <a href="{$short}" class="text-[#89b4fa] hover:underline underline-offset-2" target="_blank">/{$code}</a>
                    <button
                        class="copy-btn opacity-0 group-hover:opacity-100 transition-opacity text-[#585b70] hover:text-[#cdd6f4]"
                        title="Copy short link"
                        onclick="navigator.clipboard.writeText('{$short}')"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                </div>
            </td>
            <td class="py-3 px-4 text-[#a6adc8] text-xs" title="{$url}">{$displayUrl}</td>
            <td class="py-3 px-4 text-[#cba6f7] text-center tabular-nums">{$clicks}</td>
            <td class="py-3 px-4 text-[#a6adc8] text-xs tabular-nums">{$created}</td>
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

    $head = layoutHead($host);
    $foot = layoutFoot($host, $githubUrl);

    return <<<HTML
    {$head}

        <header class="mb-12">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#cba6f7" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                    <h1 class="text-xl font-bold text-[#cba6f7]">{$host}</h1>
                </div>
                <a href="/api" class="text-xs text-[#a6adc8] hover:text-[#cdd6f4] transition-colors flex items-center gap-1.5 border border-[#313244] rounded px-3 py-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    API
                </a>
            </div>
            <p class="text-[#a6adc8] text-sm mt-2">Paste a long URL. Get a short one. That's it.</p>
        </header>

        {$flashHtml}

        <form method="POST" action="/" class="mb-10">
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

        <div class="flex gap-6 mb-6 text-xs text-[#a6adc8]">
            <span>{$totalLinks} {$linkLabel}</span>
            <span>{$totalClicks} {$clickLabel} total</span>
        </div>

        <section>
            <div class="border border-[#313244] rounded">
                {$tableOrEmpty}
            </div>
        </section>

    {$foot}
    HTML;
}

// ─── API Docs view ───────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $config
 */
function renderApiDocs(string $baseUrl, string $githubUrl, array $config): string
{
    $host           = parse_url($baseUrl, PHP_URL_HOST) ?? $baseUrl;
    $base           = htmlspecialchars($baseUrl);
    $codeLen        = (int) $config['api_code_length'];
    $exampleCode    = str_repeat('x', $codeLen); // placeholder for docs

    $head = layoutHead($host . ' — API');
    $foot = layoutFoot($host, $githubUrl);

    // Helper to render an endpoint block.
    $endpoint = static function (
        string $method,
        string $path,
        string $desc,
        string $request,
        string $response,
        string $curl
    ) use ($base): string {
        $methodColor = match ($method) {
            'POST'   => 'text-[#a6e3a1]',
            'GET'    => 'text-[#89b4fa]',
            'DELETE' => 'text-[#f38ba8]',
            default  => 'text-[#cdd6f4]',
        };

        $requestBlock = $request !== ''
            ? <<<HTML
            <div class="mb-3">
                <p class="text-xs uppercase tracking-widest text-[#a6adc8] mb-1">Request body</p>
                <pre class="bg-[#11111b] rounded p-3 text-xs text-[#cdd6f4] overflow-x-auto">{$request}</pre>
            </div>
            HTML
            : '';

        return <<<HTML
        <div class="border border-[#313244] rounded mb-6">
            <div class="px-4 py-3 border-b border-[#313244] flex items-center gap-3">
                <span class="text-xs font-bold {$methodColor} uppercase tracking-widest w-10">{$method}</span>
                <code class="text-sm text-[#cdd6f4]">{$base}{$path}</code>
            </div>
            <div class="px-4 py-4 text-sm text-[#a6adc8]">
                <p class="mb-4">{$desc}</p>
                {$requestBlock}
                <div class="mb-3">
                    <p class="text-xs uppercase tracking-widest text-[#a6adc8] mb-1">Response</p>
                    <pre class="bg-[#11111b] rounded p-3 text-xs text-[#cdd6f4] overflow-x-auto">{$response}</pre>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-widest text-[#a6adc8] mb-1">curl example</p>
                    <pre class="bg-[#11111b] rounded p-3 text-xs text-[#cba6f7] overflow-x-auto">{$curl}</pre>
                </div>
            </div>
        </div>
        HTML;
    };

    $e1 = $endpoint(
        'POST',
        '/api/shorten',
        "Create a short link. Returns 201 on success, 422 on validation failure. API-generated codes are {$codeLen} characters long.",
        htmlspecialchars('{ "url": "https://example.com/very/long/path" }'),
        htmlspecialchars(json_encode([
            'code'       => $exampleCode,
            'short'      => $baseUrl . '/' . $exampleCode,
            'url'        => 'https://example.com/very/long/path',
            'clicks'     => 0,
            'created_at' => '2026-03-26 12:00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        htmlspecialchars("curl -X POST {$baseUrl}/api/shorten \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"url\": \"https://example.com/very/long/path\"}'")
    );

    $e2 = $endpoint(
        'GET',
        '/api/links',
        'Return all shortened links ordered by creation date descending.',
        '',
        htmlspecialchars(json_encode([[
            'code'       => $exampleCode,
            'short'      => $baseUrl . '/' . $exampleCode,
            'url'        => 'https://example.com/very/long/path',
            'clicks'     => 4,
            'created_at' => '2026-03-26 12:00:00',
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        htmlspecialchars("curl {$baseUrl}/api/links")
    );

    $e3 = $endpoint(
        'GET',
        '/api/links/{code}',
        'Return a single link by its short code. Returns 404 if not found.',
        '',
        htmlspecialchars(json_encode([
            'code'       => $exampleCode,
            'short'      => $baseUrl . '/' . $exampleCode,
            'url'        => 'https://example.com/very/long/path',
            'clicks'     => 4,
            'created_at' => '2026-03-26 12:00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        htmlspecialchars("curl {$baseUrl}/api/links/{$exampleCode}")
    );

    $e4 = $endpoint(
        'DELETE',
        '/api/links/{code}',
        'Delete a link by its short code. Returns 204 No Content on success, 404 if not found.',
        '',
        '204 No Content',
        htmlspecialchars("curl -X DELETE {$baseUrl}/api/links/{$exampleCode}")
    );

    return <<<HTML
    {$head}

        <header class="mb-12">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cba6f7" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    <h1 class="text-xl font-bold text-[#cba6f7]">API Reference</h1>
                </div>
                <a href="/" class="text-xs text-[#a6adc8] hover:text-[#cdd6f4] transition-colors flex items-center gap-1.5 border border-[#313244] rounded px-3 py-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    back
                </a>
            </div>
            <p class="text-[#a6adc8] text-sm mt-2">
                REST API &mdash; all responses are <code class="text-[#cdd6f4]">application/json</code>.
                No authentication required.
            </p>
        </header>

        <section class="mb-8">
            <h2 class="text-xs uppercase tracking-widest text-[#a6adc8] mb-5">Endpoints</h2>
            {$e1}
            {$e2}
            {$e3}
            {$e4}
        </section>

        <section class="border border-[#313244] rounded px-4 py-4 text-sm text-[#a6adc8] mb-6">
            <p class="text-xs uppercase tracking-widest text-[#a6adc8] mb-3">Error format</p>
            <pre class="bg-[#11111b] rounded p-3 text-xs text-[#f38ba8] overflow-x-auto">{ "error": "url is required." }</pre>
        </section>

    {$foot}
    HTML;
}
