<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Session;

final class ArraySession implements SessionInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = []) {}

    public function string(string $key, ?string $default = null): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_string($v) ? $v : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->data[$key] ?? null;
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
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_bool($v) ? $v : $default;
    }

    public function array(string $key): ?array
    {
        $v = $this->data[$key] ?? null;
        return is_array($v) ? $v : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($this->data[$key]);
    }
}
