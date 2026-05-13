<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Persistence\SqlTenantCommunicationSettingsRepository;

/**
 * Communications module tenant-isolation suite.
 *
 * Wave B (this commit) wires the settings-table check ONLY. The four other
 * domain isolation tests (outbox, meeting, newsletter, suppression) are
 * stubbed with markTestSkipped() and will be populated as their respective
 * waves land:
 *   - Wave C → outbox + suppression (mailer + drain + bounce handling)
 *   - Wave D → meeting (kokouskutsut + reminder cron)
 *   - Wave E → newsletter (newsletter draft + send flow)
 *
 * Active test verifies that a SMTP DSN saved by `daems` tenant is not
 * visible (via findForTenant) to the `sahegroup` tenant. This is the core
 * cross-tenant guarantee for the most sensitive piece of communications
 * state (encrypted credentials).
 */
final class CommunicationsTenantIsolationTest extends IsolationTestCase
{
    private Connection $connection;
    private SqlTenantCommunicationSettingsRepository $settingsRepo;

    protected function setUp(): void
    {
        // Bypass IsolationTestCase::setUp() (which hard-codes runMigrationsUpTo(96))
        // and jump straight to 98 — the communications module schema slot. Re-running
        // `runMigrationsUpTo` after another call to it duplicates ALTER TABLE
        // statements and fails ("Duplicate column name 'country'"), so we have to
        // skip the grandparent migration call.
        \Daems\Tests\Integration\MigrationTestCase::setUp();
        $this->runMigrationsUpTo(98);
        $this->seedTenants();

        $this->connection = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
        $this->settingsRepo = new SqlTenantCommunicationSettingsRepository($this->connection);
    }

    public function test_settings_isolation(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Save SMTP DSN for daems tenant. Migration 098 already seeded a row
        // per tenant, so this is an UPDATE (idempotent).
        $this->settingsRepo->save(new TenantCommunicationSettings(
            tenantId:               $daems,
            smtpDsnEncrypted:       'daems-secret-cipher-blob',
            mailFromAddress:        'noreply@daems.test',
            mailDisplayName:        'Daems',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable('now'),
        ));

        // Sanity: daems sees its own DSN.
        $own = $this->settingsRepo->findForTenant($daems);
        self::assertSame('daems-secret-cipher-blob', $own->smtpDsnEncrypted);

        // Core isolation guarantee: sahegroup MUST NOT see daems' DSN.
        $other = $this->settingsRepo->findForTenant($sahe);
        self::assertNull(
            $other->smtpDsnEncrypted,
            'sahegroup tenant must not see daems tenant SMTP DSN — cross-tenant credential leak',
        );
        // And the recovered settings must belong to sahegroup, not daems.
        self::assertTrue(
            $other->tenantId->equals($sahe),
            'returned settings must be scoped to the requested tenant',
        );
    }

    public function test_outbox_isolation(): void
    {
        $this->markTestSkipped('Wave C — mail outbox table + repo land later.');
    }

    public function test_meeting_isolation(): void
    {
        $this->markTestSkipped('Wave D — meetings flow lands later.');
    }

    public function test_newsletter_isolation(): void
    {
        $this->markTestSkipped('Wave E — newsletter draft flow lands later.');
    }

    public function test_suppression_isolation(): void
    {
        $this->markTestSkipped('Wave G — suppression list flow lands later.');
    }
}
