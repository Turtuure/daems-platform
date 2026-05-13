<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Persistence\Sql;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

/**
 * Integration tests for the new SqlTenantRepository methods landed in
 * Wave D Task D7: update(), suspend(), reactivate(), and the i18n field
 * round-trip through the JSON columns introduced by migration 071.
 */
final class SqlTenantRepositoryExtensionTest extends MigrationTestCase
{
    private SqlTenantRepository $repo;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        // SqlTenantRepository SELECTs `currency` (added in migration 097)
        // alongside the 071 i18n columns. Bump HWM accordingly.
        $this->runMigrationsUpTo(97);

        $this->repo = new SqlTenantRepository($this->pdo());

        // Seed an isolated tenant for each test so we don't churn the
        // daems/sahegroup fixtures created by 019/072.
        $this->tenantId = TenantId::generate();
        $this->pdo()->prepare(
            "INSERT INTO tenants (id, slug, name, display_name_i18n, public_description_i18n,
                                  supported_locales, default_locale, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $this->tenantId->value(),
            'd7-test',
            'D7Test',
            json_encode(['en_GB' => 'D7 Test'], JSON_UNESCAPED_UNICODE),
            null,
            'en_GB',
            'en_GB',
        ]);
    }

    public function test_findById_hydrates_all_new_fields(): void
    {
        $found = $this->repo->findById($this->tenantId);
        self::assertNotNull($found);
        self::assertSame('d7-test', $found->slug->value());
        self::assertSame('D7 Test', $found->displayName('en_GB'));
        self::assertSame(['en_GB'], $found->supportedLocales());
        self::assertSame('en_GB', $found->defaultLocale());
        self::assertFalse($found->suspended());
    }

    public function test_update_persists_i18n_fields(): void
    {
        $existing = $this->repo->findById($this->tenantId);
        self::assertNotNull($existing);

        $updated = new Tenant(
            id: $existing->id,
            slug: $existing->slug,
            name: $existing->name,
            createdAt: $existing->createdAt,
            memberNumberPrefix: 'D7-',
            defaultTimeFormat: $existing->defaultTimeFormat,
            displayNameI18n: ['fi_FI' => 'Sevenkos D7', 'en_GB' => 'D7 Society'],
            publicDescriptionI18n: ['en_GB' => 'A test tenant', 'fi_FI' => 'Testiyhdistys'],
            supportedLocales: ['fi_FI', 'en_GB'],
            defaultLocale: 'fi_FI',
        );
        $this->repo->update($updated);

        $reloaded = $this->repo->findById($this->tenantId);
        self::assertNotNull($reloaded);
        self::assertSame('Sevenkos D7', $reloaded->displayName('fi_FI'));
        self::assertSame('D7 Society',  $reloaded->displayName('en_GB'));
        self::assertSame('Testiyhdistys', $reloaded->publicDescription('fi_FI'));
        self::assertSame(['fi_FI', 'en_GB'], $reloaded->supportedLocales());
        self::assertSame('fi_FI', $reloaded->defaultLocale());
        self::assertSame('D7-',  $reloaded->memberNumberPrefix);
    }

    public function test_update_round_trips_unicode_finnish_characters(): void
    {
        $existing = $this->repo->findById($this->tenantId);
        self::assertNotNull($existing);

        $updated = new Tenant(
            id: $existing->id,
            slug: $existing->slug,
            name: $existing->name,
            createdAt: $existing->createdAt,
            memberNumberPrefix: $existing->memberNumberPrefix,
            defaultTimeFormat: $existing->defaultTimeFormat,
            displayNameI18n: ['fi_FI' => 'Pääkäyttäjäyhdistys ry', 'en_GB' => 'Power Users'],
            publicDescriptionI18n: ['fi_FI' => 'Yhdistys käyttäjille — älä häiritse'],
            supportedLocales: ['fi_FI', 'en_GB'],
            defaultLocale: 'fi_FI',
        );
        $this->repo->update($updated);

        $reloaded = $this->repo->findById($this->tenantId);
        self::assertNotNull($reloaded);
        self::assertSame('Pääkäyttäjäyhdistys ry', $reloaded->displayName('fi_FI'));
        self::assertSame('Yhdistys käyttäjille — älä häiritse', $reloaded->publicDescription('fi_FI'));
    }

    public function test_suspend_then_reactivate_cycle(): void
    {
        $now = new DateTimeImmutable('2026-05-07 14:00:00');
        $this->repo->suspend($this->tenantId, 'GDPR review pending', $now);

        $suspended = $this->repo->findById($this->tenantId);
        self::assertNotNull($suspended);
        self::assertTrue($suspended->suspended());
        self::assertSame('GDPR review pending', $suspended->suspendedReason());
        self::assertNotNull($suspended->suspendedAt());

        $this->repo->reactivate($this->tenantId);

        $alive = $this->repo->findById($this->tenantId);
        self::assertNotNull($alive);
        self::assertFalse($alive->suspended());
        self::assertNull($alive->suspendedAt());
        self::assertNull($alive->suspendedReason());
    }
}
