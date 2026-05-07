<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use DateTimeImmutable;
use DomainException;
use PDO;

final class SqlTenantRepository implements TenantRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    private const SELECT_COLUMNS = 'id, slug, name, created_at, member_number_prefix, default_time_format,
            display_name_i18n, public_description_i18n, supported_locales, default_locale,
            suspended_at, suspended_reason';

    public function findById(TenantId $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM tenants WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByDomain(string $domain): ?Tenant
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.slug, t.name, t.created_at, t.member_number_prefix, t.default_time_format,
                    t.display_name_i18n, t.public_description_i18n, t.supported_locales, t.default_locale,
                    t.suspended_at, t.suspended_reason
             FROM tenants t
             JOIN tenant_domains td ON td.tenant_id = t.id
             WHERE td.domain = ? LIMIT 1'
        );
        $stmt->execute([strtolower(trim($domain))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<Tenant> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT ' . self::SELECT_COLUMNS . ' FROM tenants ORDER BY slug');
        if ($stmt === false) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    /**
     * @param array<mixed, mixed> $row
     */
    private function hydrate(array $row): Tenant
    {
        $id      = is_string($row['id']         ?? null) ? $row['id']         : throw new DomainException('Corrupt tenants.id');
        $slug    = is_string($row['slug']        ?? null) ? $row['slug']        : throw new DomainException('Corrupt tenants.slug');
        $name    = is_string($row['name']        ?? null) ? $row['name']        : throw new DomainException('Corrupt tenants.name');
        $created = is_string($row['created_at']  ?? null) ? $row['created_at']  : throw new DomainException('Corrupt tenants.created_at');

        $prefix  = isset($row['member_number_prefix']) && is_string($row['member_number_prefix'])
            ? $row['member_number_prefix']
            : null;

        $tf = $row['default_time_format'] ?? '24';
        $defaultTimeFormat = ($tf === '12' || $tf === '24') ? (string) $tf : '24';

        $displayNameI18n       = self::decodeJsonMap($row['display_name_i18n']       ?? null);
        $publicDescriptionI18n = self::decodeJsonMap($row['public_description_i18n'] ?? null);
        $supportedLocales      = self::decodeLocaleList($row['supported_locales'] ?? null);
        $defaultLocale         = isset($row['default_locale']) && is_string($row['default_locale']) && $row['default_locale'] !== ''
            ? $row['default_locale']
            : 'en_GB';

        $suspendedAt = null;
        if (isset($row['suspended_at']) && is_string($row['suspended_at']) && $row['suspended_at'] !== '') {
            $suspendedAt = new DateTimeImmutable($row['suspended_at']);
        }
        $suspendedReason = null;
        if (isset($row['suspended_reason']) && is_string($row['suspended_reason']) && $row['suspended_reason'] !== '') {
            $suspendedReason = $row['suspended_reason'];
        }

        return new Tenant(
            id: TenantId::fromString($id),
            slug: TenantSlug::fromString($slug),
            name: $name,
            createdAt: new DateTimeImmutable($created),
            memberNumberPrefix: $prefix,
            defaultTimeFormat: $defaultTimeFormat,
            displayNameI18n: $displayNameI18n,
            publicDescriptionI18n: $publicDescriptionI18n,
            supportedLocales: $supportedLocales,
            defaultLocale: $defaultLocale,
            suspendedAt: $suspendedAt,
            suspendedReason: $suspendedReason,
        );
    }

    /**
     * Decode a JSON column into an `array<string, string>` map. Anything that
     * isn't a `{string: string}` shape is filtered: the entity treats null as
     * "no i18n provided" and falls back to the technical `name`.
     *
     * @return array<string, string>|null
     */
    private static function decodeJsonMap(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out === [] ? null : $out;
    }

    /** @return list<string> */
    private static function decodeLocaleList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return ['en_GB'];
        }
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        ));
        return $parts === [] ? ['en_GB'] : $parts;
    }

    public function updatePrefix(TenantId $tenantId, ?string $prefix): void
    {
        $stmt = $this->pdo->prepare('UPDATE tenants SET member_number_prefix = ? WHERE id = ?');
        $stmt->execute([$prefix, $tenantId->value()]);
    }

    public function updateDefaultTimeFormat(TenantId $tenantId, string $format): void
    {
        if ($format !== '12' && $format !== '24') {
            throw new DomainException("Invalid time format: {$format}");
        }
        $stmt = $this->pdo->prepare('UPDATE tenants SET default_time_format = ? WHERE id = ?');
        $stmt->execute([$format, $tenantId->value()]);
    }

    /**
     * Persist editable fields: name, display_name_i18n, public_description_i18n,
     * supported_locales, default_locale, member_number_prefix. The slug is
     * immutable post-creation; suspension state is owned by suspend()/reactivate().
     */
    public function update(Tenant $tenant): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants
             SET name                    = ?,
                 display_name_i18n       = ?,
                 public_description_i18n = ?,
                 supported_locales       = ?,
                 default_locale          = ?,
                 member_number_prefix    = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $tenant->name,
            self::encodeJsonMap($tenant->displayNameI18n()),
            self::encodeJsonMap($tenant->publicDescriptionI18n()),
            implode(',', $tenant->supportedLocales()),
            $tenant->defaultLocale(),
            $tenant->memberNumberPrefix,
            $tenant->id->value(),
        ]);
    }

    public function suspend(TenantId $tenantId, string $reason, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants
             SET suspended_at     = ?,
                 suspended_reason = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $now->format('Y-m-d H:i:s'),
            $reason,
            $tenantId->value(),
        ]);
    }

    public function reactivate(TenantId $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants
             SET suspended_at     = NULL,
                 suspended_reason = NULL
             WHERE id = ?'
        );
        $stmt->execute([$tenantId->value()]);
    }

    /**
     * Encode the entity's i18n map exactly as it was provided. NULL maps
     * are preserved as NULL in the column — the entity's accessor returns
     * the raw stored shape.
     *
     * @param array<string, string>|null $map
     */
    private static function encodeJsonMap(?array $map): ?string
    {
        if ($map === null || $map === []) {
            return null;
        }
        $encoded = json_encode($map, JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }
}
