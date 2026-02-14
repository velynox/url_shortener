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
     * Escapes all literal characters outside {param} blocks so that
     * characters like '+' are treated as plain text, not regex quantifiers.
     *
     * Example: '/{code}+' → '#^/(?P<code>[^/]+)\+$#'
     */
    private function patternToRegex(string $pattern): string
    {
        // Split into alternating [literal, param, literal, param, ...] chunks.
        $regex = preg_replace_callback(
            '/\{(\w+)\}|([^{]+)/',
            static function (array $m): string {
                // Named param token.
                if ($m[1] !== '') {
                    return '(?P<' . $m[1] . '>[^/]+)';
                }
                // Literal segment — escape for use inside a regex.
                return preg_quote($m[2], '#');
            },
            $pattern
        );

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
