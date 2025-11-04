<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Link.php';
require_once __DIR__ . '/src/Router.php';

use App\Database;
use App\Link;
use App\Router;

// Bootstrap dependencies.
$db     = new Database(__DIR__ . '/db/links.sqlite');
$links  = new Link($db);
$router = new Router();

// Determine the base URL for displaying short links.
$scheme  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

// ─── Routes ──────────────────────────────────────────────────────────────────

// Homepage: show the form + list of all links.
$router->get('/', function () use ($links, $baseUrl) {
    $all   = $links->all();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    echo renderHome($all, $baseUrl, $flash);
});

// Handle form submission.
$router->post('/', function () use ($links, $baseUrl) {
    session_start();

    $url = trim($_POST['url'] ?? '');

    if ($url === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'URL cannot be empty.'];
        header('Location: /');
        exit;
    }

    try {
        $code = $links->create($url);
        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Short link created.',
            'short'   => $baseUrl . '/s/' . $code,
        ];
    } catch (\InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }

    header('Location: /');
    exit;
});

// Redirect short link to original URL.
$router->get('/s/{code}', function (array $params) use ($links) {
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

// ─── View ────────────────────────────────────────────────────────────────────

/**
 * Render the homepage HTML.
 *
 * @param array<int, array<string, mixed>> $all
 * @param array<string, string>|null       $flash
 */
function renderHome(array $all, string $baseUrl, ?array $flash): string
{
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
        $code    = htmlspecialchars($link['code']);
        $url     = htmlspecialchars($link['url']);
        $short   = htmlspecialchars($baseUrl . '/s/' . $link['code']);
        $clicks  = (int) $link['clicks'];
        $created = htmlspecialchars($link['created_at']);

        // Truncate long URLs for display.
        $displayUrl = strlen($url) > 55 ? substr($url, 0, 52) . '...' : $url;

        $rows .= <<<HTML
        <tr class="border-t border-[#313244] hover:bg-[#181825] transition-colors">
            <td class="py-3 px-4">
                <a href="{$short}" class="text-[#89b4fa] hover:underline underline-offset-2" target="_blank">/s/{$code}</a>
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
        : '<p class="text-[#a6adc8] text-sm">No links yet. Create one above.</p>';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>url shortener</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: { mono: ['"JetBrains Mono"', 'monospace'] }
                    }
                }
            }
        </script>
        <style>
            * { font-family: 'JetBrains Mono', monospace; }
            body { background-color: #1e1e2e; color: #cdd6f4; }
        </style>
    </head>
    <body class="min-h-screen py-20 px-6">
        <div class="max-w-3xl mx-auto">

            <!-- Header -->
            <header class="mb-12">
                <div class="flex items-center gap-3 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#cba6f7" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                    <h1 class="text-xl font-bold text-[#cba6f7]">url shortener</h1>
                </div>
                <p class="text-[#a6adc8] text-sm">Paste a long URL. Get a short one. That's it.</p>
            </header>

            <!-- Flash message -->
            {$flashHtml}

            <!-- Form -->
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

            <!-- Links table -->
            <section>
                <h2 class="text-xs uppercase tracking-widest text-[#a6adc8] mb-4">All Links</h2>
                <div class="border border-[#313244] rounded">
                    {$tableOrEmpty}
                </div>
            </section>

            <!-- Footer -->
            <footer class="mt-16 text-[#585b70] text-xs text-center">
                velynox.de &mdash; local instance
            </footer>

        </div>
    </body>
    </html>
    HTML;
}
