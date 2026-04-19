<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Session;

interface SessionInterface
{
    public function string(string $key, ?string $default = null): ?string;

    public function int(string $key, ?int $default = null): ?int;

    public function bool(string $key, ?bool $default = null): ?bool;

    /**
     * @return array<array-key, mixed>|null
     */
    public function array(string $key): ?array;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function unset(string $key): void;
}
