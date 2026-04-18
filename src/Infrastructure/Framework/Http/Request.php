<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
    ) {}

    public static function fromGlobals(): self
    {
        $uri = strtok(rawurldecode($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($type, 'application/json')) {
                $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
            } else {
                $body = $_POST;
            }
        }

        return new self($method, $uri, $_GET, $body, getallheaders() ?: []);
    }

    public function method(): string { return $this->method; }
    public function uri(): string { return $this->uri; }
    public function query(string $key, mixed $default = null): mixed { return $this->query[$key] ?? $default; }
    public function input(string $key, mixed $default = null): mixed { return $this->body[$key] ?? $default; }
    public function all(): array { return $this->body; }
    public function header(string $key, ?string $default = null): ?string { return $this->headers[$key] ?? $default; }
}
