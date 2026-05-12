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
 * Scenario 1: GSA bootstraps board → propose approve_basic →
 * 3 board members vote yes → decision passes.
 */
final class BootstrapAndApproveBasicE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-12T12:00:00Z'));
    }

    public function test_bootstrap_propose_and_vote_passes_decision(): void
    {
        // Seed GSA (bootstrap actor)
        $gsa = $this->h->seedPlatformAdmin('gsa@gov.test');
        $gsaToken = $this->h->tokenFor($gsa);

        // Seed 3 board-eligible users (membership_type=FULL, status=active)
        $chair  = $this->seedFullMember('chair@gov.test');
        $memberB = $this->seedFullMember('memberb@gov.test');
        $memberC = $this->seedFullMember('memberc@gov.test');

        // Seed admin user who will propose the decision
        $admin = $this->h->seedUser('admin@gov.test', 'pass1234', 'admin');
        $adminToken = $this->h->tokenFor($admin);

        // Bootstrap the board
        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/board/bootstrap', $gsaToken, [
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
        self::assertSame(201, $resp->status(), 'Bootstrap should return 201');
        $boardBody = json_decode($resp->body(), true);
        self::assertArrayHasKey('board_id', $boardBody);

        // Propose approve_basic (admin is NOT a board member; the propose-endpoint
        // allows any admin tenant user to submit proposals)
        $appId = 'test-application-id-001';
        $resp2 = $this->h->authedRequest('POST', '/api/v1/backstage/governance/decisions/approve-basic', $adminToken, [
            'application_id'  => $appId,
            'vote_visibility' => 'visible',
        ]);
        self::assertSame(201, $resp2->status(), 'Propose should return 201');
        $proposeBody = json_decode($resp2->body(), true);
        self::assertArrayHasKey('decision_id', $proposeBody);
        $decisionId = $proposeBody['decision_id'];

        // Board members cast their votes — each member needs a token
        $chairToken   = $this->h->tokenFor($chair);
        $memberBToken = $this->h->tokenFor($memberB);
        $memberCToken = $this->h->tokenFor($memberC);

        // chair votes yes
        $v1 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$decisionId}/vote", $chairToken, ['vote' => 'yes']);
        self::assertSame(200, $v1->status(), 'Chair vote should return 200');

        // memberB votes yes
        $v2 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$decisionId}/vote", $memberBToken, ['vote' => 'yes']);
        self::assertSame(200, $v2->status(), 'MemberB vote should return 200');

        // memberC votes yes — this is the final vote; unanimous threshold met
        $v3 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$decisionId}/vote", $memberCToken, ['vote' => 'yes']);
        self::assertSame(200, $v3->status(), 'MemberC vote should return 200');

        // Verify decision is now Passed
        $resp3 = $this->h->authedRequest('GET', "/api/v1/backstage/governance/decisions/{$decisionId}", $adminToken);
        self::assertSame(200, $resp3->status());
        $detail = json_decode($resp3->body(), true);
        self::assertSame(BoardDecisionStatus::Passed->value, $detail['decision']['status'] ?? null, 'Decision should be passed after all-yes votes');
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
