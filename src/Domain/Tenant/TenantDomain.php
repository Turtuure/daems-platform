<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use InvalidArgumentException;

final class TenantDomain
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $v = strtolower(trim($value));
        if ($v === '' || strlen($v) > 255) {
            throw new InvalidArgumentException('TenantDomain must be 1-255 chars.');
        }
        // Accept: plain `localhost`, subdomain.example.tld, devhost.local, etc.
        if (preg_match(
            '/^(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|localhost)$/',
            $v
        ) !== 1) {
            throw new InvalidArgumentException("TenantDomain invalid: {$value}");
        }
        return new self($v);
    }

    public function value(): string
    {
        return $this->value;
    }
}
