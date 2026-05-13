<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Communications;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailTemplateId;
use DaemsModule\Communications\Domain\Template\MailTemplate;
use PHPUnit\Framework\TestCase;

/**
 * E2E coverage for the Wave D Task D8 template-overrides HTTP API:
 *   GET /api/v1/backstage/communications/templates/{kind}/{locale} → show
 *   PUT /api/v1/backstage/communications/templates/{kind}/{locale} → update
 *
 * Uses KernelHarness — module bindings.test.php wires the InMemory template
 * repo singleton, so we exercise the live router/auth stack and inspect
 * side effects via the same instance the controller writes to.
 */
final class TemplatesE2ETest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-14T09:00:00Z'));

        $admin = $this->h->seedUser('admin-templates@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_gets_template_overrides(): void
    {
        $tenantId = $this->h->daemsTenantId();

        // Seed an existing row so GET has something meaningful to return.
        $this->h->commsTemplates->saveOverrides(new MailTemplate(
            id:              MailTemplateId::generate(),
            tenantId:        $tenantId,
            kind:            MailKind::MeetingInvitation,
            locale:          SupportedLocale::fromString('fi_FI'),
            stringOverrides: [
                'subject'    => 'Kokouskutsu — testimuokattu',
                'intro_text' => 'Hei :first_name, tässä on omat tervetuloasanani.',
                'signature'  => 'Yhdistys ry',
            ],
            updatedAt:       new \DateTimeImmutable('2026-05-10T10:00:00Z'),
            updatedBy:       \Daems\Domain\User\UserId::generate(),
        ));

        $resp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/communications/templates/meeting_invitation/fi_FI',
            $this->adminToken,
        );

        self::assertSame(200, $resp->status(), 'show: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        $data = $body['data'];
        self::assertSame($tenantId->value(), $data['tenant_id']);
        self::assertSame('meeting_invitation', $data['kind']);
        self::assertSame('fi_FI', $data['locale']);
        self::assertSame('Kokouskutsu — testimuokattu', $data['overrides']['subject']);
        self::assertSame('Hei :first_name, tässä on omat tervetuloasanani.', $data['overrides']['intro_text']);
        self::assertSame('Yhdistys ry', $data['overrides']['signature']);
        self::assertNotEmpty($data['updated_at']);
        self::assertNotEmpty($data['updated_by']);

        // Empty-row case → empty overrides map but still 200.
        $emptyResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/communications/templates/group_message/en_GB',
            $this->adminToken,
        );
        self::assertSame(200, $emptyResp->status(), 'show-empty: ' . $emptyResp->body());
        $emptyBody = json_decode($emptyResp->body(), true);
        self::assertIsArray($emptyBody);
        self::assertSame([], $emptyBody['data']['overrides']);
        self::assertNull($emptyBody['data']['updated_at']);
        self::assertNull($emptyBody['data']['updated_by']);
    }

    public function test_admin_saves_template_overrides(): void
    {
        $tenantId = $this->h->daemsTenantId();

        $payload = [
            'overrides' => [
                'subject'    => 'Membership approved',
                'intro_text' => 'Welcome :first_name.',
                'signature'  => 'The Board',
                'footer'     => 'Reg. no. 1234',
            ],
        ];

        $resp = $this->h->authedRequest(
            'PUT',
            '/api/v1/backstage/communications/templates/membership_approved/en_GB',
            $this->adminToken,
            $payload,
        );

        self::assertSame(200, $resp->status(), 'update: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertTrue($body['data']['success']);
        self::assertNotEmpty($body['data']['updated_at']);

        // Verify the InMemory repo singleton received the row.
        $stored = $this->h->commsTemplates->findOverrides(
            $tenantId,
            MailKind::MembershipApproved,
            SupportedLocale::fromString('en_GB'),
        );
        self::assertNotNull($stored);
        self::assertSame('Membership approved', $stored->stringOverrides['subject']);
        self::assertSame('Welcome :first_name.', $stored->stringOverrides['intro_text']);
        self::assertSame('The Board', $stored->stringOverrides['signature']);
        self::assertSame('Reg. no. 1234', $stored->stringOverrides['footer']);

        // GET after PUT round-trips the values.
        $getResp = $this->h->authedRequest(
            'GET',
            '/api/v1/backstage/communications/templates/membership_approved/en_GB',
            $this->adminToken,
        );
        self::assertSame(200, $getResp->status());
        $getBody = json_decode($getResp->body(), true);
        self::assertSame('Membership approved', $getBody['data']['overrides']['subject']);
    }
}
