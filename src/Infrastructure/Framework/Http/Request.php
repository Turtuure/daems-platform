<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\UnauthorizedException;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $server,
        private readonly ?ActingUser $actingUser = null,
        /** @var array<string, mixed> */
        private readonly array $attributes = [],
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

        return new self($method, $uri, $_GET, $body, getallheaders() ?: [], $_SERVER);
    }

    public static function forTesting(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $headers = [],
        array $server = [],
    ): self {
        return new self($method, $uri, $query, $body, $headers, $server);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[$key] ?? $this->headers[strtolower($key)] ?? $default;
    }

    public function actingUser(): ?ActingUser
    {
        return $this->actingUser;
    }

    /**
     * Non-null variant. Controllers whose route is gated by AuthMiddleware
     * should prefer this — it keeps the null check out of every handler
     * and gives a clean 401 via the Kernel exception mapping if the route
     * somehow reaches the controller without an attached ActingUser.
     */
    public function requireActingUser(): ActingUser
    {
        return $this->actingUser ?? throw new UnauthorizedException();
    }

    public function withActingUser(ActingUser $user): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->query,
            $this->body,
            $this->headers,
            $this->server,
            $user,
            $this->attributes,
        );
    }

    public function attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->query,
            $this->body,
            $this->headers,
            $this->server,
            $this->actingUser,
            [...$this->attributes, $key => $value],
        );
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['Authorization'] ?? $this->headers['authorization'] ?? null;
        if ($auth === null) {
            return null;
        }
        if (!str_starts_with($auth, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($auth, 7));
        return $token === '' ? null : $token;
    }

    public function clientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
