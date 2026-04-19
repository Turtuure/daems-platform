<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Session;

use RuntimeException;

final class Session implements SessionInterface
{
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            throw new RuntimeException('Session not started — call session_start() first.');
        }
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $v = $_SESSION[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_string($v) ? $v : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $_SESSION[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $v = $_SESSION[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_bool($v) ? $v : $default;
    }

    public function array(string $key): ?array
    {
        $v = $_SESSION[$key] ?? null;
        return is_array($v) ? $v : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
