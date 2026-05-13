<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;
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
    private SqlMailOutboxRepository $outboxRepo;

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
        $this->outboxRepo   = new SqlMailOutboxRepository($this->connection);
    }

    /** Insert a user row directly (FK requirement for mail_outbox.queued_by). */
    private function ensureUser(string $userId): UserId
    {
        $sel = $this->pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
        $sel->execute([$userId]);
        if ($sel->fetchColumn() === false) {
            $ins = $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$userId, 'Queuer', "queuer-{$userId}@test", 'x', '1990-01-01', 0]);
        }
        return UserId::fromString($userId);
    }

    private function makeOutboxRow(TenantId $tenantId, UserId $queuedBy, string $recipient): MailOutbox
    {
        return new MailOutbox(
            id:                  MailOutboxId::generate(),
            tenantId:            $tenantId,
            kind:                MailKind::GroupMessage,
            category:            MailKind::GroupMessage->category(),
            recipientEmail:      $recipient,
            recipientUserId:     null,
            locale:              SupportedLocale::fromString('fi_FI'),
            subject:             'Isolation row',
            bodyHtml:            '<p>Hi</p>',
            bodyText:            'Hi',
            payloadVars:         [],
            payloadMeetingId:    null,
            payloadInvoiceId:    null,
            payloadNewsletterId: null,
            status:              MailOutboxStatus::Queued,
            attemptCount:        0,
            lastError:           null,
            queuedAt:            new \DateTimeImmutable('now'),
            sentAt:              null,
            queuedBy:            $queuedBy,
        );
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
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Each tenant queues one outbox row from a tenant-local user.
        $daemsUser = $this->ensureUser('01958000-0000-7000-8000-0000000000d1');
        $saheUser  = $this->ensureUser('01958000-0000-7000-8000-0000000000d2');

        $daemsRow = $this->makeOutboxRow($daems, $daemsUser, 'daems-recipient@example.com');
        $saheRow  = $this->makeOutboxRow($sahe,  $saheUser,  'sahe-recipient@example.com');

        $this->outboxRepo->save($daemsRow);
        $this->outboxRepo->save($saheRow);

        // daems → only the daems row.
        $daemsList = $this->outboxRepo->listForTenant($daems, [], 1, 50);
        self::assertCount(1, $daemsList);
        self::assertSame($daemsRow->id->value(), $daemsList[0]->id->value());
        self::assertSame('daems-recipient@example.com', $daemsList[0]->recipientEmail);

        // sahegroup → only the sahegroup row. The core isolation guarantee:
        // sahegroup MUST NOT see the daems outbox row.
        $saheList = $this->outboxRepo->listForTenant($sahe, [], 1, 50);
        self::assertCount(1, $saheList);
        self::assertSame($saheRow->id->value(), $saheList[0]->id->value());
        self::assertSame('sahe-recipient@example.com', $saheList[0]->recipientEmail);

        // Count helper must respect the same scoping.
        self::assertSame(1, $this->outboxRepo->countForTenant($daems, []));
        self::assertSame(1, $this->outboxRepo->countForTenant($sahe,  []));
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
