<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Daems\Infrastructure\Framework\Container\Container;
use Throwable;

final class Kernel
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request): Response
    {
        try {
            $router = $this->container->make(Router::class);
            return $router->dispatch($request);
        } catch (Throwable $e) {
            return Response::serverError($e->getMessage());
        }
    }

    public function send(Response $response): void
    {
        $response->send();
    }
}
