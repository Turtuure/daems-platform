<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage\Governance;

use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BillingFeeSchedulesEndpointTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-11-01T12:00:00Z'));
    }

    public function test_post_creates_active_rows_for_non_formal_tenant(): void
    {
        $this->setRequiresFormalDecisionForFees(false);
        $admin = $this->h->seedUser('admin@billing.test', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/billing/fee-schedules', $token, [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ]);

        $this->assertSame(201, $resp->status(), "response: " . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertCount(3, $body['schedule_ids']);
        $this->assertNull($body['decision_id']);
    }

    public function test_post_creates_decision_for_formal_tenant(): void
    {
        $this->setRequiresFormalDecisionForFees(true);
        // The formal path requires a board — bootstrap one first.
        $gsa = $this->h->seedPlatformAdmin('gsa@billing.test');
        $gsaToken = $this->h->tokenFor($gsa);
        $boardMember = $this->seedFullMember('member@billing.test');
        $bootstrapResp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/board/bootstrap', $gsaToken, [
            'members' => [[
                'user_id'         => $boardMember->id()->value(),
                'role'            => 'chair',
                'term_started_at' => '2026-01-01T00:00:00Z',
                'term_ends_at'    => '2028-01-01T00:00:00Z',
            ]],
        ]);
        $this->assertSame(201, $bootstrapResp->status(), "board bootstrap failed: " . $bootstrapResp->body());

        $admin = $this->h->seedUser('admin@billing.test', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/billing/fee-schedules', $token, [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ]);

        $this->assertSame(201, $resp->status(), "response: " . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertNotNull($body['decision_id']);
    }

    public function test_non_admin_gets_403(): void
    {
        $member = $this->h->seedUser('member@billing.test', 'pass1234', 'member');
        $token = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/billing/fee-schedules', $token, [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ]);
        $this->assertSame(403, $resp->status());
    }

    public function test_get_returns_rows_for_year(): void
    {
        $this->setRequiresFormalDecisionForFees(false);
        $admin = $this->h->seedUser('admin@billing.test', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $this->h->authedRequest('POST', '/api/v1/backstage/governance/billing/fee-schedules', $token, [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ]);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/governance/billing/fee-schedules?year=2027', $token);
        $this->assertSame(200, $resp->status(), "response: " . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertSame(2027, $body['year']);
        $this->assertCount(3, $body['rows']);
    }

    private function setRequiresFormalDecisionForFees(bool $value): void
    {
        $repo = $this->h->container->make(TenantGovernanceSettingsRepositoryInterface::class);
        $tenantId = $this->h->daemsTenantId();
        $existing = $repo->find($tenantId);
        $repo->save(new TenantGovernanceSettings(
            tenantId:                      $tenantId,
            expulsionHearingDays:          $existing?->expulsionHearingDays ?? 14,
            decisionExpirationDays:        $existing?->decisionExpirationDays ?? 60,
            requiresFormalDecisionForFees: $value,
            defaultDueDaysFromAnniversary: 60,
            overdueGraceDays:              30,
            lapseCheckEnabled:             true,
        ));
    }

    /**
     * Seeds a user with membership_type=FULL (board-eligible).
     * Pattern lifted from BootstrapAndApproveBasicE2ETest::seedFullMember.
     */
    private function seedFullMember(string $email): User
    {
        $user = new User(
            id: UserId::generate(),
            name: 'Full Member',
            email: $email,
            passwordHash: password_hash('pass1234', PASSWORD_BCRYPT),
            dateOfBirth: '1985-06-15',
            membershipType: 'FULL',
            membershipStatus: 'active',
        );
        $this->h->users->save($user);
        return $user;
    }
}
