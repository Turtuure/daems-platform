<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Communications;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;
use DaemsModule\Communications\Tests\Support\InMemoryAudienceResolver;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

/**
 * E2E coverage for the Wave E Task E4 newsletter HTTP API:
 *   GET    /api/v1/backstage/communications/newsletters             → index
 *   POST   /api/v1/backstage/communications/newsletters             → create
 *   PATCH  /api/v1/backstage/communications/newsletters/{id}        → update
 *   DELETE /api/v1/backstage/communications/newsletters/{id}        → destroy
 *   POST   /api/v1/backstage/communications/newsletters/{id}/send   → send
 *
 * Uses KernelHarness — module bindings.test.php wires the InMemory newsletter
 * repo singleton + the InMemoryAudienceResolver, so we exercise the live
 * router/auth stack and inspect side effects via the same singletons the
 * controller sees.
 */
final class NewslettersE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-14T09:00:00Z'));

        $admin = $this->h->seedUser('admin-newsletters@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_creates_newsletter_draft(): void
    {
        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/communications/newsletters',
            $this->adminToken,
            ['internal_name' => 'Spring 2026 update'],
        );

        self::assertSame(201, $resp->status(), 'create: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('id', $body['data']);

        $newsletterId = (string) $body['data']['id'];
        self::assertNotEmpty($newsletterId);

        // Verify side effect — the draft exists in the repo with Draft status.
        $tenantId = $this->h->daemsTenantId();
        $list = $this->h->commsNewsletters->listForTenant($tenantId);
        self::assertCount(1, $list);
        self::assertSame($newsletterId, $list[0]->id->value());
        self::assertSame('Spring 2026 update', $list[0]->internalName);
        self::assertSame(NewsletterStatus::Draft, $list[0]->status);
    }

    public function test_admin_updates_newsletter_blocks(): void
    {
        // Create a draft first.
        $createResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/communications/newsletters',
            $this->adminToken,
            ['internal_name' => 'Block round-trip'],
        );
        self::assertSame(201, $createResp->status(), 'create: ' . $createResp->body());
        $created = json_decode($createResp->body(), true);
        $newsletterId = (string) $created['data']['id'];

        $payload = [
            'internal_name' => 'Block round-trip v2',
            'subject_i18n'  => [
                'fi_FI' => 'Kevätviesti',
                'en_GB' => 'Spring update',
                'sw_TZ' => 'Habari ya msimu',
            ],
            'blocks_i18n' => [
                'fi_FI' => [
                    ['type' => 'heading',   'level' => 1, 'text' => 'Tervetuloa'],
                    ['type' => 'paragraph', 'markdown' => 'Hei kaikki **jäsenet**.'],
                    ['type' => 'divider'],
                    ['type' => 'button', 'text' => 'Lue lisää', 'url' => 'https://example.com/news'],
                ],
                'en_GB' => [
                    ['type' => 'heading',   'level' => 1, 'text' => 'Welcome'],
                    ['type' => 'paragraph', 'markdown' => 'Hello **members**.'],
                ],
                'sw_TZ' => [
                    ['type' => 'paragraph', 'markdown' => 'Habari wanachama.'],
                ],
            ],
            'audience' => [
                'membershipTypes'     => ['regular'],
                'locales'             => ['fi_FI'],
                'joinedWithin'        => 'last_90_days',
                'applicationStatuses' => [],
            ],
        ];

        $patchResp = $this->h->authedRequest(
            'PATCH',
            '/api/v1/backstage/communications/newsletters/' . $newsletterId,
            $this->adminToken,
            $payload,
        );

        self::assertSame(200, $patchResp->status(), 'update: ' . $patchResp->body());
        $patchBody = json_decode($patchResp->body(), true);
        self::assertIsArray($patchBody);
        self::assertSame($newsletterId, $patchBody['data']['id']);

        // Verify side effect — round-trip via the index endpoint to confirm
        // serialized blocks survive the payload → entity → JSON round-trip.
        $listResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/communications/newsletters',
            $this->adminToken,
        );
        self::assertSame(200, $listResp->status(), 'list: ' . $listResp->body());
        $listBody = json_decode($listResp->body(), true);
        self::assertIsArray($listBody);
        self::assertCount(1, $listBody['data']);
        $row = $listBody['data'][0];

        self::assertSame('Block round-trip v2', $row['internal_name']);
        self::assertSame('Kevätviesti', $row['subject_i18n']['fi_FI']);
        self::assertSame('Spring update', $row['subject_i18n']['en_GB']);
        self::assertSame('Habari ya msimu', $row['subject_i18n']['sw_TZ']);
        self::assertCount(4, $row['blocks_i18n']['fi_FI']);
        self::assertSame('heading', $row['blocks_i18n']['fi_FI'][0]['type']);
        self::assertSame('Tervetuloa', $row['blocks_i18n']['fi_FI'][0]['text']);
        self::assertSame('paragraph', $row['blocks_i18n']['fi_FI'][1]['type']);
        self::assertSame('divider', $row['blocks_i18n']['fi_FI'][2]['type']);
        self::assertSame('button', $row['blocks_i18n']['fi_FI'][3]['type']);
        self::assertSame('https://example.com/news', $row['blocks_i18n']['fi_FI'][3]['url']);
        self::assertSame(['regular'], $row['audience']['membershipTypes']);
        self::assertSame('last_90_days', $row['audience']['joinedWithin']);
    }

    public function test_admin_sends_newsletter(): void
    {
        $tenantId = $this->h->daemsTenantId();

        // 1. Seed SMTP-configured settings.
        $this->h->commsSettings->save(new TenantCommunicationSettings(
            tenantId:               $tenantId,
            smtpDsnEncrypted:       'cipher-blob',
            mailFromAddress:        'noreply@daems.test',
            mailDisplayName:        'Daems Test',
            mailReplyTo:            null,
            smtpTestSucceededAt:    new \DateTimeImmutable('2026-05-13T09:00:00Z'),
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      '#2e5c8a',
            brandFooterAddress:     null,
            updatedAt:              new \DateTimeImmutable('2026-05-01T00:00:00Z'),
        ));

        // 2. Seed audience: two opt-in recipients.
        $resolver = $this->h->commsAudience;
        self::assertInstanceOf(InMemoryAudienceResolver::class, $resolver);
        $resolver->seed($tenantId, [
            new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       'alice@example.com',
                locale:      SupportedLocale::fromString('fi_FI'),
                firstName:   'Alice',
                contextVars: [],
            ),
            new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       'bob@example.com',
                locale:      SupportedLocale::fromString('en_GB'),
                firstName:   'Bob',
                contextVars: [],
            ),
        ]);

        // 3. Create a draft.
        $createResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/communications/newsletters',
            $this->adminToken,
            ['internal_name' => 'Sendable newsletter'],
        );
        self::assertSame(201, $createResp->status(), 'create: ' . $createResp->body());
        $createBody = json_decode($createResp->body(), true);
        $newsletterId = (string) $createBody['data']['id'];

        // 4. Fill blocks + subjects for all 3 locales (avoids parity failure).
        $patchPayload = [
            'internal_name' => 'Sendable newsletter',
            'subject_i18n'  => [
                'fi_FI' => 'Kevät 2026',
                'en_GB' => 'Spring 2026',
                'sw_TZ' => 'Msimu 2026',
            ],
            'blocks_i18n' => [
                'fi_FI' => [['type' => 'heading',   'level' => 1, 'text' => 'Tervetuloa']],
                'en_GB' => [['type' => 'heading',   'level' => 1, 'text' => 'Welcome']],
                'sw_TZ' => [['type' => 'paragraph', 'markdown' => 'Karibu']],
            ],
            'audience' => [
                'membershipTypes'     => [],
                'locales'             => [],
                'joinedWithin'        => null,
                'applicationStatuses' => [],
            ],
        ];
        $patchResp = $this->h->authedRequest(
            'PATCH',
            '/api/v1/backstage/communications/newsletters/' . $newsletterId,
            $this->adminToken,
            $patchPayload,
        );
        self::assertSame(200, $patchResp->status(), 'update: ' . $patchResp->body());

        // 5. Send.
        $sendResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/communications/newsletters/' . $newsletterId . '/send',
            $this->adminToken,
        );
        self::assertSame(200, $sendResp->status(), 'send: ' . $sendResp->body());
        $sendBody = json_decode($sendResp->body(), true);
        self::assertIsArray($sendBody);
        self::assertSame(2, $sendBody['data']['enqueued_count']);

        // 6. Verify outbox has rows queued + draft flipped to Sent.
        $outboxRows = $this->h->commsOutbox->listForTenant($tenantId, [], 1, 100);
        self::assertCount(2, $outboxRows);

        $draft = $this->h->commsNewsletters->findById(
            \DaemsModule\Communications\Domain\Mail\NewsletterId::fromString($newsletterId),
        );
        self::assertNotNull($draft);
        self::assertSame(NewsletterStatus::Sent, $draft->status);
        self::assertNotNull($draft->sentAt);
    }
}
