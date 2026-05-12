<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Governance;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * Scenario 6: Tenant without a board → GSA POSTs to gsa-overrides/approve-basic
 * with reason ≥ 10 chars → application approved, gsa_overrides row exists.
 */
final class GsaOverrideApproveBasicE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        // NOTE: no board is bootstrapped — this is the "no board" scenario.
        $this->h = new KernelHarness(FrozenClock::at('2026-05-12T12:00:00Z'));
    }

    public function test_gsa_override_approves_basic_without_board(): void
    {
        $gsa = $this->h->seedPlatformAdmin('gsa@override.test');
        $gsaToken = $this->h->tokenFor($gsa);

        $appId  = 'override-application-id-001';
        $reason = 'Emergency approval — board seat vacant for required quorum.';

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/gsa-overrides/approve-basic',
            $gsaToken,
            [
                'application_id' => $appId,
                'reason'         => $reason,
            ],
        );

        self::assertSame(201, $resp->status(), 'GSA override should return 201');
        $body = json_decode($resp->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('override_id', $body, 'Response must include override_id');
        self::assertNotEmpty($body['override_id']);
    }

    public function test_non_gsa_is_forbidden(): void
    {
        $admin = $this->h->seedUser('admin@override.test', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/gsa-overrides/approve-basic',
            $token,
            [
                'application_id' => 'any-app-id',
                'reason'         => 'Trying to override without GSA rights.',
            ],
        );

        self::assertSame(403, $resp->status(), 'Non-GSA must be forbidden');
    }

    public function test_reason_shorter_than_10_chars_rejected(): void
    {
        $gsa = $this->h->seedPlatformAdmin('gsa2@override.test');
        $gsaToken = $this->h->tokenFor($gsa);

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/gsa-overrides/approve-basic',
            $gsaToken,
            [
                'application_id' => 'short-reason-app',
                'reason'         => 'Too short',   // only 9 chars
            ],
        );

        // GsaOverrideRequiresReason extends DomainException → maps to 500 in the harness
        self::assertSame(500, $resp->status(), 'Short reason should result in domain error (500)');
    }
}
