<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A tenant_domains row. The hostname IS the primary key (table 019), so the
 * hostname doubles as the entity identifier passed to repository methods that
 * take `string $domainId`.
 *
 * For legacy callers that only want hostname validation as a value-object,
 * `TenantDomain::fromString($hostname)` still works — it constructs an entity
 * with placeholder tenantId/isPrimary/createdAt that callers must overwrite
 * via `withTenant()` / `withPrimary()` before persisting.
 */
final class TenantDomain
{
    public function __construct(
        private readonly string $hostname,
        private readonly ?TenantId $tenantId,
        private readonly bool $isPrimary,
        private readonly ?DateTimeImmutable $createdAt,
    ) {
        self::validateHostname($hostname);
    }

    /**
     * Hostname-only factory. Kept for existing callers that only need
     * validation as a value object. The resulting entity has no tenantId,
     * is non-primary, and has no createdAt — callers persisting it must
     * call `withTenant()` and (optionally) `withPrimary()` first.
     */
    public static function fromString(string $value): self
    {
        $v = strtolower(trim($value));
        return new self($v, null, false, null);
    }

    /** Full-entity factory for repository hydration / use cases. */
    public static function create(
        string $hostname,
        TenantId $tenantId,
        bool $isPrimary,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(strtolower(trim($hostname)), $tenantId, $isPrimary, $createdAt);
    }

    /** The hostname IS the id (it's the table PK). */
    public function id(): string
    {
        return $this->hostname;
    }

    /** Convenience alias preserved from the legacy value-object API. */
    public function value(): string
    {
        return $this->hostname;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }

    public function tenantId(): ?TenantId
    {
        return $this->tenantId;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function withPrimary(bool $primary): self
    {
        return new self($this->hostname, $this->tenantId, $primary, $this->createdAt);
    }

    public function withTenant(TenantId $tenantId): self
    {
        return new self($this->hostname, $tenantId, $this->isPrimary, $this->createdAt);
    }

    public function withCreatedAt(DateTimeImmutable $createdAt): self
    {
        return new self($this->hostname, $this->tenantId, $this->isPrimary, $createdAt);
    }

    private static function validateHostname(string $value): void
    {
        if ($value === '' || strlen($value) > 255) {
            throw new InvalidArgumentException('TenantDomain must be 1-255 chars.');
        }
        // Accept: plain `localhost`, subdomain.example.tld, devhost.local, etc.
        if (preg_match(
            '/^(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|localhost)$/',
            $value
        ) !== 1) {
            throw new InvalidArgumentException("TenantDomain invalid: {$value}");
        }
    }
}
