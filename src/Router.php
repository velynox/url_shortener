<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal request router.
 * Matches the current request method + path against registered routes.
 * Supports named capture groups via {param} syntax in patterns.
 */
class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes['GET'][$pattern] = $handler;
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes['POST'][$pattern] = $handler;
    }

    /**
     * Dispatch the current request against registered routes.
     */
    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            if (preg_match($this->patternToRegex($pattern), $path, $matches)) {
                $handler(array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY));
                return;
            }
        }

        http_response_code(404);
        echo $this->render404();
    }

    /**
     * Convert a route pattern into a named-capture regex.
     *
     * Segments outside {param} blocks are regex-escaped so literal characters
     * like '+' are treated as plain text, not quantifiers.
     *
     * Example: '/{code}+' → '#^/(?P<code>[^/]+)\+$#'
     */
    private function patternToRegex(string $pattern): string
    {
        // Split on {param} tokens, escape everything else, reassemble.
        $parts  = preg_split('/(\{(\w+)\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex  = '';

        for ($i = 0; $i < count($parts); $i++) {
            if (preg_match('/^\{(\w+)\}$/', $parts[$i], $m)) {
                $regex .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($parts[$i], '#');
            }
        }

        return '#^' . $regex . '$#';
    }

    private function render404(): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>404 — Not Found</title>
            <style>body{background:#1e1e2e;color:#cdd6f4;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}</style>
        </head>
        <body>
            <p>404 — short link not found.</p>
        </body>
        </html>
        HTML;
    }
}
