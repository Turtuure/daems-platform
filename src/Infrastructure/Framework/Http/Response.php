<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

final class Response
{
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        $body = $data === null
            ? ''
            : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            $body,
        );
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return self::json(['error' => $message], 404);
    }

    public static function badRequest(string $message): self
    {
        return self::json(['error' => $message], 400);
    }

    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::json(['error' => $message], 500);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return self::json(['error' => $message], 401);
    }

    public static function forbidden(string $message = 'Forbidden.'): self
    {
        return self::json(['error' => $message], 403);
    }

    public static function tooManyRequests(string $message, int $retryAfter): self
    {
        return new self(
            429,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Retry-After'  => (string) $retryAfter,
            ],
            (string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
