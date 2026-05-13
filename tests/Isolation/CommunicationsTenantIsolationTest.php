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
use DaemsModule\Communications\Domain\Meeting\Meeting;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Meeting\MeetingStatus;
use DaemsModule\Communications\Domain\Meeting\MeetingType;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMailOutboxRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlMeetingRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlNewsletterDraftRepository;
use DaemsModule\Communications\Infrastructure\Persistence\SqlTenantCommunicationSettingsRepository;

/**
 * Communications module tenant-isolation suite.
 *
 * Wave-by-wave activation:
 *   - Wave B → settings (active)
 *   - Wave C → outbox (active); suppression still pending
 *   - Wave D → meeting (active as of D9); newsletter still pending
 *   - Wave E → newsletter (TBD)
 *   - Wave G → suppression (TBD)
 *
 * The settings-isolation case verifies that a SMTP DSN saved by `daems`
 * tenant is not visible (via findForTenant) to the `sahegroup` tenant — the
 * core cross-tenant guarantee for the most sensitive piece of communications
 * state (encrypted credentials). The outbox + meeting cases extend the same
 * scoping guarantee to the row-level repositories.
 */
final class CommunicationsTenantIsolationTest extends IsolationTestCase
{
    private Connection $connection;
    private SqlTenantCommunicationSettingsRepository $settingsRepo;
    private SqlMailOutboxRepository $outboxRepo;
    private SqlMeetingRepository $meetingRepo;
    private SqlNewsletterDraftRepository $newsletterRepo;

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
        $this->settingsRepo   = new SqlTenantCommunicationSettingsRepository($this->connection);
        $this->outboxRepo     = new SqlMailOutboxRepository($this->connection);
        $this->meetingRepo    = new SqlMeetingRepository($this->connection);
        $this->newsletterRepo = new SqlNewsletterDraftRepository($this->connection);
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
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Each tenant creates one Meeting from a tenant-local user. Re-use the
        // ensureUser helper (also used by the outbox test) so the FK on
        // meetings.created_by is satisfied without re-seeding the tenant
        // membership row.
        $daemsUser = $this->ensureUser('01958000-0000-7000-8000-0000000000e1');
        $saheUser  = $this->ensureUser('01958000-0000-7000-8000-0000000000e2');

        $daemsMeeting = new Meeting(
            id:                  MeetingId::generate(),
            tenantId:            $daems,
            type:                MeetingType::AnnualMeeting,
            titleByLocale:       ['fi_FI' => 'Vuosikokous 2026'],
            startsAt:            new \DateTimeImmutable('2026-06-01T18:00:00Z'),
            location:            'Helsinki',
            remoteUrl:           null,
            agendaItemsByLocale: ['fi_FI' => ['Avaus', 'Tilinpäätös']],
            documentUrls:        [],
            status:              MeetingStatus::Scheduled,
            createdAt:           new \DateTimeImmutable('2026-05-10T08:00:00Z'),
            createdBy:           $daemsUser,
        );
        $saheMeeting = new Meeting(
            id:                  MeetingId::generate(),
            tenantId:            $sahe,
            type:                MeetingType::BoardMeeting,
            titleByLocale:       ['en_GB' => 'Board meeting Q2'],
            startsAt:            new \DateTimeImmutable('2026-06-15T10:00:00Z'),
            location:            null,
            remoteUrl:           'https://meet.example.com/sahe-q2',
            agendaItemsByLocale: ['en_GB' => ['Welcome', 'Strategy']],
            documentUrls:        [],
            status:              MeetingStatus::Scheduled,
            createdAt:           new \DateTimeImmutable('2026-05-10T08:00:00Z'),
            createdBy:           $saheUser,
        );

        $this->meetingRepo->save($daemsMeeting);
        $this->meetingRepo->save($saheMeeting);

        // daems sees only the daems meeting (with no date filter).
        $daemsList = $this->meetingRepo->listForTenant($daems);
        self::assertCount(1, $daemsList);
        self::assertSame($daemsMeeting->id->value(), $daemsList[0]->id->value());
        self::assertSame('Helsinki', $daemsList[0]->location);

        // Core isolation guarantee: sahegroup MUST NOT see the daems meeting.
        $saheList = $this->meetingRepo->listForTenant($sahe);
        self::assertCount(1, $saheList);
        self::assertSame($saheMeeting->id->value(), $saheList[0]->id->value());
        self::assertSame('https://meet.example.com/sahe-q2', $saheList[0]->remoteUrl);

        // Reverse direction — date-bounded query stays scoped too.
        $daemsBounded = $this->meetingRepo->listForTenant(
            $daems,
            new \DateTimeImmutable('2026-05-01T00:00:00Z'),
            new \DateTimeImmutable('2026-12-31T23:59:59Z'),
        );
        self::assertCount(1, $daemsBounded);
        self::assertSame($daemsMeeting->id->value(), $daemsBounded[0]->id->value());

        $saheBounded = $this->meetingRepo->listForTenant(
            $sahe,
            new \DateTimeImmutable('2026-05-01T00:00:00Z'),
            new \DateTimeImmutable('2026-12-31T23:59:59Z'),
        );
        self::assertCount(1, $saheBounded);
        self::assertSame($saheMeeting->id->value(), $saheBounded[0]->id->value());
    }

    public function test_newsletter_isolation(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        // Each tenant creates one NewsletterDraft from a tenant-local user.
        // newsletter_drafts.created_by FKs into users (per the migration), so
        // re-use the same ensureUser helper as the outbox/meeting tests.
        $daemsUser = $this->ensureUser('01958000-0000-7000-8000-0000000000f1');
        $saheUser  = $this->ensureUser('01958000-0000-7000-8000-0000000000f2');

        $daemsDraft = new NewsletterDraft(
            id:               NewsletterId::generate(),
            tenantId:         $daems,
            internalName:     'Daems spring newsletter',
            subjectByLocale:  ['fi_FI' => 'Kevätviesti'],
            blocksByLocale:   ['fi_FI' => []],
            audience:         new AudienceFilter([], [], null, []),
            status:           NewsletterStatus::Draft,
            sentAt:           null,
            createdAt:        new \DateTimeImmutable('2026-05-10T08:00:00Z'),
            createdBy:        $daemsUser,
        );
        $saheDraft = new NewsletterDraft(
            id:               NewsletterId::generate(),
            tenantId:         $sahe,
            internalName:     'Sahegroup Q2 update',
            subjectByLocale:  ['en_GB' => 'Q2 update'],
            blocksByLocale:   ['en_GB' => []],
            audience:         new AudienceFilter([], [], null, []),
            status:           NewsletterStatus::Draft,
            sentAt:           null,
            createdAt:        new \DateTimeImmutable('2026-05-10T08:00:00Z'),
            createdBy:        $saheUser,
        );

        $this->newsletterRepo->save($daemsDraft);
        $this->newsletterRepo->save($saheDraft);

        // daems sees only its own draft.
        $daemsList = $this->newsletterRepo->listForTenant($daems);
        self::assertCount(1, $daemsList);
        self::assertSame($daemsDraft->id->value(), $daemsList[0]->id->value());
        self::assertSame('Daems spring newsletter', $daemsList[0]->internalName);

        // Core isolation guarantee: sahegroup MUST NOT see the daems draft.
        $saheList = $this->newsletterRepo->listForTenant($sahe);
        self::assertCount(1, $saheList);
        self::assertSame($saheDraft->id->value(), $saheList[0]->id->value());
        self::assertSame('Sahegroup Q2 update', $saheList[0]->internalName);
    }

    public function test_suppression_isolation(): void
    {
        $this->markTestSkipped('Wave G — suppression list flow lands later.');
    }
}
