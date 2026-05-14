<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Communications;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use DaemsModule\Communications\Application\GetCommunicationSettings\GetCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Mailer\InMemoryMailer;
use PHPUnit\Framework\TestCase;

/**
 * E2E coverage for the Wave C8 settings HTTP API:
 *   GET  /api/v1/backstage/communications/settings           → show
 *   PUT  /api/v1/backstage/communications/settings           → update
 *   POST /api/v1/backstage/communications/settings/smtp-test → testSmtp
 *
 * Uses KernelHarness — module bindings.test.php wires the InMemory settings
 * repo + InMemoryMailer, so we exercise the live router/auth stack and
 * inspect side effects via the same singletons the controller sees.
 */
final class SettingsE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-14T09:00:00Z'));

        $admin = $this->h->seedUser('admin-settings@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);

        // Seed a baseline settings row for the tenant so GET has something
        // meaningful to return. Use the same repo singleton the controller will see.
        $tenantId = $this->h->daemsTenantId();
        $this->h->commsSettings->save(new TenantCommunicationSettings(
            tenantId:               $tenantId,
            smtpDsnEncrypted:       'pre-seeded-cipher-blob',
            mailFromAddress:        'noreply@daems.test',
            mailDisplayName:        'Daems Test',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      null,
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable('2026-05-01T00:00:00Z'),
        ));
    }

    public function test_admin_reads_settings(): void
    {
        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/communications/settings',
            $this->adminToken,
        );

        self::assertSame(200, $resp->status(), 'show: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        $data = $body['data'];
        self::assertSame('noreply@daems.test', $data['mail_from_address']);
        self::assertSame('Daems Test',         $data['mail_display_name']);
        self::assertSame(7,                    $data['reminder_pre_due_days']);
        self::assertSame([14, 30],             $data['reminder_post_due_days']);
        self::assertSame(30,                   $data['lapse_warning_days_before']);

        // Tenant admin (non-GSA) must see the DSN masked as '***'.
        self::assertSame(GetCommunicationSettings::MASKED_DSN, $data['smtp_dsn']);
        self::assertTrue($data['dsn_configured']);
        self::assertTrue($data['dsn_masked']);
    }

    public function test_admin_saves_settings(): void
    {
        $payload = [
            'smtp_dsn'                  => 'smtps://user:newpass@smtp.example.com:465',
            'mail_from_address'         => 'updated@daems.test',
            'mail_display_name'         => 'Updated Display',
            'reminder_pre_due_days'     => 14,
            'reminder_post_due_days'    => [10, 20, 45],
            'lapse_warning_days_before' => 14,
            'brand_primary_color'       => '#0066cc',
        ];

        $resp = $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/communications/settings',
            $this->adminToken,
            $payload,
        );

        self::assertSame(200, $resp->status(), 'update: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertSame(['data' => ['success' => true]], $body);

        // Verify via the same repo singleton the controller writes to.
        $stored = $this->h->commsSettings->findForTenant($this->h->daemsTenantId());
        self::assertSame('updated@daems.test', $stored->mailFromAddress);
        self::assertSame('Updated Display',    $stored->mailDisplayName);
        self::assertSame(14,                   $stored->reminderPreDueDays);
        self::assertSame([10, 20, 45],         $stored->reminderPostDueDays);
        self::assertSame(14,                   $stored->lapseWarningDaysBefore);
        self::assertSame('#0066cc',            $stored->brandPrimaryColor);

        // DSN ciphertext must have been re-encrypted (= different from the
        // pre-seeded literal) AND must NOT be the plaintext input — the use
        // case encrypts before persisting.
        self::assertNotNull($stored->smtpDsnEncrypted);
        self::assertNotSame('pre-seeded-cipher-blob', $stored->smtpDsnEncrypted);
        self::assertNotSame($payload['smtp_dsn'], $stored->smtpDsnEncrypted);
    }

    public function test_admin_sends_smtp_test(): void
    {
        $mailer = $this->h->commsMailer;
        self::assertInstanceOf(InMemoryMailer::class, $mailer);
        $mailer->clear();

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/communications/settings/smtp-test',
            $this->adminToken,
            ['recipient_email' => 'audit@example.com'],
        );

        self::assertSame(200, $resp->status(), 'smtp-test: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertTrue($body['data']['success']);
        self::assertNotEmpty($body['data']['sent_at']);

        // The InMemoryMailer captured the call.
        self::assertCount(1, $mailer->sent);
        $sent = $mailer->sent[0];
        self::assertSame('audit@example.com', $sent['row']->recipientEmail);

        // smtp_test_succeeded_at must have been bumped on the settings row.
        $after = $this->h->commsSettings->findForTenant($this->h->daemsTenantId());
        self::assertNotNull($after->smtpTestSucceededAt);
    }
}
