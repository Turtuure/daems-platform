<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Governance;

use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\User\UserId;
use Daems\Domain\User\User;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * Scenario 4: Bootstrap board → propose+pass delegate_authority(approve_basic→admin)
 * → admin POSTs approve-basic → decision auto-passes with via_delegation=true.
 */
final class DelegationApproveBasicE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-12T12:00:00Z'));
    }

    public function test_delegation_causes_approve_basic_to_auto_pass(): void
    {
        $gsa = $this->h->seedPlatformAdmin('gsa@deleg.test');
        $gsaToken = $this->h->tokenFor($gsa);

        $chair   = $this->seedFullMember('chair@deleg.test');
        $memberB = $this->seedFullMember('memberb@deleg.test');
        $memberC = $this->seedFullMember('memberc@deleg.test');

        // Bootstrap board
        $boot = $this->h->authedRequest('POST', '/api/v1/backstage/governance/board/bootstrap', $gsaToken, [
            'members' => [
                [
                    'user_id'         => $chair->id()->value(),
                    'role'            => 'chair',
                    'term_started_at' => '2026-01-01T00:00:00Z',
                    'term_ends_at'    => '2028-01-01T00:00:00Z',
                ],
                [
                    'user_id'         => $memberB->id()->value(),
                    'role'            => 'member',
                    'term_started_at' => '2026-01-01T00:00:00Z',
                    'term_ends_at'    => '2028-01-01T00:00:00Z',
                ],
                [
                    'user_id'         => $memberC->id()->value(),
                    'role'            => 'member',
                    'term_started_at' => '2026-01-01T00:00:00Z',
                    'term_ends_at'    => '2028-01-01T00:00:00Z',
                ],
            ],
        ]);
        self::assertSame(201, $boot->status());

        // An admin user (will propose the delegation and later test approve-basic)
        $admin = $this->h->seedUser('admin@deleg.test', 'pass1234', 'admin');
        $adminToken = $this->h->tokenFor($admin);

        // Propose delegate_authority for approve_basic → admin role
        $delegPropResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/decisions/delegate-authority',
            $adminToken,
            [
                'decision_type'     => 'approve_basic',
                'delegated_to_role' => 'admin',
                'vote_visibility'   => 'visible',
                'meeting_reference' => 'Board meeting 2026-05-12',
            ],
        );
        self::assertSame(201, $delegPropResp->status(), 'Propose delegation should return 201');
        $delegBody = json_decode($delegPropResp->body(), true);
        $delegDecisionId = $delegBody['decision_id'];

        // All 3 board members vote yes → passes synchronously → DelegateAuthorityExecutor
        // creates an active BoardDelegation in the repository.
        $chairToken   = $this->h->tokenFor($chair);
        $memberBToken = $this->h->tokenFor($memberB);
        $memberCToken = $this->h->tokenFor($memberC);

        $vr1 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$delegDecisionId}/vote", $chairToken,   ['vote' => 'yes']);
        self::assertSame(200, $vr1->status());
        $vr2 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$delegDecisionId}/vote", $memberBToken, ['vote' => 'yes']);
        self::assertSame(200, $vr2->status());
        $vr3 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$delegDecisionId}/vote", $memberCToken, ['vote' => 'yes']);
        self::assertSame(200, $vr3->status());

        // Confirm the delegation decision is now passed
        $delegDetailResp = $this->h->authedRequest('GET', "/api/v1/backstage/governance/decisions/{$delegDecisionId}", $adminToken);
        self::assertSame(200, $delegDetailResp->status());
        $delegDetailBody = json_decode($delegDetailResp->body(), true);
        self::assertSame(BoardDecisionStatus::Passed->value, $delegDetailBody['decision']['status'] ?? null, 'Delegation decision should be passed');

        // Now admin POSTs approve-basic — the controller tries ApproveBasicAsDelegate first
        // (which finds the active delegation) and the resulting decision is immediately Passed.
        $appId = 'deleg-application-id-001';
        $approveResp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/governance/decisions/approve-basic',
            $adminToken,
            [
                'application_id'  => $appId,
                'vote_visibility' => 'visible',
            ],
        );
        self::assertSame(201, $approveResp->status(), 'Delegated approve-basic should return 201');
        $approveBody = json_decode($approveResp->body(), true);
        $approveDecisionId = $approveBody['decision_id'];

        // Verify: decision is passed and via_delegation=true
        $detailResp = $this->h->authedRequest('GET', "/api/v1/backstage/governance/decisions/{$approveDecisionId}", $adminToken);
        self::assertSame(200, $detailResp->status());
        $detail = json_decode($detailResp->body(), true);
        self::assertSame(BoardDecisionStatus::Passed->value, $detail['decision']['status'] ?? null, 'Decision should be immediately passed via delegation');
        self::assertTrue($detail['decision']['via_delegation'] ?? false, 'Decision should have via_delegation=true');
        self::assertSame($appId, $detail['decision']['payload']['application_id'] ?? null);
    }

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
