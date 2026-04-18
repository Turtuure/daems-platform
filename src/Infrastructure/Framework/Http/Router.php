<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

final class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $path, 'handler' => $handler];
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $path, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->uri());
            if ($params !== null) {
                return ($route['handler'])($request, $params);
            }
        }
        return Response::notFound('Route not found');
    }

    private function match(string $pattern, string $uri): ?array
    {
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<\1>[^/]+)', $pattern);
        if (!preg_match('#^' . $regex . '$#', $uri, $matches)) {
            return null;
        }
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
