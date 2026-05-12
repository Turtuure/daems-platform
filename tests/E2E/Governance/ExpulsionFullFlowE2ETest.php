<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Governance;

use Daems\Domain\User\UserId;
use Daems\Domain\User\User;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

/**
 * Scenario 3: Bootstrap board → initiate expulsion → submit statement →
 * advance to vote → all yes → user expelled. Then file appeal → status=appealed.
 */
final class ExpulsionFullFlowE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-05-12T12:00:00Z'));
    }

    public function test_expulsion_full_flow_expelled_then_appealed(): void
    {
        $gsa = $this->h->seedPlatformAdmin('gsa@exp.test');
        $gsaToken = $this->h->tokenFor($gsa);

        // One chair + two regular board members (all FULL+active)
        $chair   = $this->seedFullMember('chair@exp.test');
        $memberB = $this->seedFullMember('memberb@exp.test');
        $memberC = $this->seedFullMember('memberc@exp.test');

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

        // Target user to be expelled
        $target = $this->seedFullMember('target@exp.test');

        $chairToken = $this->h->tokenFor($chair);

        // Initiate expulsion (chair is a board member)
        $initResp = $this->h->authedRequest('POST', '/api/v1/backstage/governance/expulsions', $chairToken, [
            'target_user_id' => $target->id()->value(),
            'reason'         => 'Gross misconduct violating § 8 of the bylaws.',
        ]);
        self::assertSame(201, $initResp->status(), 'Initiate expulsion should return 201');
        $initBody = json_decode($initResp->body(), true);
        self::assertArrayHasKey('expulsion_id', $initBody);
        $expulsionId = $initBody['expulsion_id'];

        // Target submits a defence statement — this satisfies the hearing
        // requirement so that advance-to-vote is not blocked by the deadline.
        // The statement endpoint requires the *target* user to be the acting user.
        $targetToken = $this->h->tokenFor($target);
        $stmtResp = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/expulsions/{$expulsionId}/statement",
            $targetToken,
            ['statement_text' => 'I contest the allegations in full.'],
        );
        self::assertSame(200, $stmtResp->status(), 'Submit statement should return 200');

        // Chair advances to vote
        $advResp = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/expulsions/{$expulsionId}/advance-to-vote",
            $chairToken,
            ['meeting_reference' => 'Board meeting 2026-05-12'],
        );
        self::assertSame(201, $advResp->status(), 'Advance to vote should return 201');
        $advBody = json_decode($advResp->body(), true);
        self::assertArrayHasKey('decision_id', $advBody);
        $decisionId = $advBody['decision_id'];

        // All three board members vote yes
        $memberBToken = $this->h->tokenFor($memberB);
        $memberCToken = $this->h->tokenFor($memberC);

        $v1 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$decisionId}/vote", $chairToken, ['vote' => 'yes']);
        self::assertSame(200, $v1->status());
        $v2 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$decisionId}/vote", $memberBToken, ['vote' => 'yes']);
        self::assertSame(200, $v2->status());
        $v3 = $this->h->authedRequest('POST', "/api/v1/backstage/governance/decisions/{$decisionId}/vote", $memberCToken, ['vote' => 'yes']);
        self::assertSame(200, $v3->status());

        // Verify expulsion record shows status=expelled
        $admin = $this->h->seedUser('admin@exp.test', 'pass1234', 'admin');
        $adminToken = $this->h->tokenFor($admin);
        $showResp = $this->h->authedRequest('GET', "/api/v1/backstage/governance/expulsions/{$expulsionId}", $adminToken);
        self::assertSame(200, $showResp->status());
        $showBody = json_decode($showResp->body(), true);
        self::assertSame('expelled', $showBody['expulsion']['status'] ?? null, 'Status should be expelled after unanimous yes vote');

        // Target files appeal — must be filed by the expelled user themselves
        $appealResp = $this->h->authedRequest(
            'POST',
            "/api/v1/backstage/governance/expulsions/{$expulsionId}/appeal",
            $targetToken,
            ['appeal_text' => 'I appeal this decision on procedural grounds.'],
        );
        self::assertSame(200, $appealResp->status(), 'Appeal should return 200');

        // Verify status=appealed
        $showResp2 = $this->h->authedRequest('GET', "/api/v1/backstage/governance/expulsions/{$expulsionId}", $adminToken);
        self::assertSame(200, $showResp2->status());
        $showBody2 = json_decode($showResp2->body(), true);
        self::assertSame('appealed', $showBody2['expulsion']['status'] ?? null, 'Status should be appealed after filing appeal');
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
