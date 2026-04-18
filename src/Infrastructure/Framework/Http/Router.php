<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Closure;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable, middleware: list<class-string<MiddlewareInterface>>}> */
    private array $routes = [];

    /** @var Closure(class-string): MiddlewareInterface */
    private Closure $resolver;

    /**
     * @param callable(class-string): MiddlewareInterface $middlewareResolver
     */
    public function __construct(callable $middlewareResolver)
    {
        $this->resolver = $middlewareResolver(...);
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => 'GET',
            'pattern'    => $path,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => 'POST',
            'pattern'    => $path,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->uri());
            if ($params === null) {
                continue;
            }
            $final = static function (Request $req) use ($route, $params): Response {
                return ($route['handler'])($req, $params);
            };
            $resolver = $this->resolver;
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                static function (callable $next, string $class) use ($resolver): callable {
                    $mw = $resolver($class);
                    return static function (Request $req) use ($mw, $next): Response {
                        return $mw->process($req, $next);
                    };
                },
                $final,
            );
            return $pipeline($request);
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
