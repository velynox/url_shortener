<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal request router.
 * Matches the current request method + path against registered routes.
 */
class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes['GET'][$pattern] = $handler;
    }

    /**
     * Register a POST route.
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->routes['POST'][$pattern] = $handler;
    }

    /**
     * Dispatch the current request.
     * Supports one named capture group: {param}.
     */
    public function dispatch(string $method, string $path): void
    {
        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $pattern => $handler) {
            $regex = $this->patternToRegex($pattern);

            if (preg_match($regex, $path, $matches)) {
                // Pass only named captures (skip the full match at index 0).
                $params = array_filter(
                    $matches,
                    fn($key) => !is_int($key),
                    ARRAY_FILTER_USE_KEY
                );
                $handler($params);
                return;
            }
        }

        // No route matched.
        http_response_code(404);
        echo $this->render404();
    }

    /**
     * Convert a route pattern like /s/{code} into a named-capture regex.
     */
    private function patternToRegex(string $pattern): string
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * Minimal 404 page — same design system, no layout duplication.
     */
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
