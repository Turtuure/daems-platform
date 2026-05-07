<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration071Test extends MigrationTestCase
{
    public function testNewColumnsAddedToTenants(): void
    {
        $this->runMigrationsUpTo(70);
        $this->runMigration('071_extend_tenants_for_management_ui.sql');

        $columns = $this->columnsOf('tenants');
        foreach ([
            'display_name_i18n',
            'public_description_i18n',
            'supported_locales',
            'default_locale',
            'suspended_at',
            'suspended_reason',
        ] as $needed) {
            $this->assertContains($needed, $columns, "expected column {$needed}");
        }
    }

    public function testDaemsRowBackfilledToFinnishDefault(): void
    {
        $this->runMigrationsUpTo(70);
        $this->runMigration('071_extend_tenants_for_management_ui.sql');

        $row = $this->pdo()->query(
            "SELECT default_locale, supported_locales, display_name_i18n FROM tenants WHERE slug = 'daems'"
        )?->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            self::markTestSkipped('No daems tenant in this fresh test DB.');
        }

        $this->assertSame('fi_FI', $row['default_locale']);
        $this->assertSame('fi_FI,en_GB,sw_TZ', $row['supported_locales']);
        $this->assertIsString($row['display_name_i18n']);
        $this->assertStringContainsString('Daem Society ry', $row['display_name_i18n']);
    }

    public function testSahegroupRowBackfilledToEnglishDefault(): void
    {
        $this->runMigrationsUpTo(70);
        $this->runMigration('071_extend_tenants_for_management_ui.sql');

        $row = $this->pdo()->query(
            "SELECT default_locale, supported_locales, display_name_i18n FROM tenants WHERE slug = 'sahegroup'"
        )?->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            self::markTestSkipped('No sahegroup tenant in this fresh test DB.');
        }

        $this->assertSame('en_GB', $row['default_locale']);
        $this->assertSame('fi_FI,en_GB,sw_TZ', $row['supported_locales']);
        $this->assertIsString($row['display_name_i18n']);
        $this->assertStringContainsString('Sahe Group', $row['display_name_i18n']);
    }

    public function testSuspendedAtIsNullable(): void
    {
        $this->runMigrationsUpTo(70);
        $this->runMigration('071_extend_tenants_for_management_ui.sql');

        $row = $this->pdo()->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'suspended_at'"
        )?->fetchColumn();

        $this->assertSame('YES', $row);
    }

    public function testNewTenantInsertGetsEnGbDefaultsViaColumnDefault(): void
    {
        $this->runMigrationsUpTo(70);
        $this->runMigration('071_extend_tenants_for_management_ui.sql');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo()->exec(
            "INSERT INTO tenants (id, slug, name, status, created_at, updated_at)
             VALUES ('44444444-4444-4444-4444-444444444444', 'newco', 'New Co', 'active', '{$now}', '{$now}')"
        );

        $row = $this->pdo()->query(
            "SELECT default_locale, supported_locales FROM tenants WHERE slug = 'newco'"
        )?->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('en_GB', $row['default_locale']);
        $this->assertSame('en_GB', $row['supported_locales']);
    }
}
