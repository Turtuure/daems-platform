<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class AdminInboxIncludesProposalsTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    private function seedPendingProposal(string $title): ProjectProposal
    {
        $proposal = new ProjectProposal(
            ProjectProposalId::generate(),
            $this->h->testTenantId,
            UserId::generate()->value(),
            'Proposer Name',
            'proposer@x.com',
            $title,
            'community',
            'Summary long enough for list.',
            'Description long enough for validation.',
            'pending',
            '2026-04-20 10:00:00',
        );
        $this->h->proposals->save($proposal);
        return $proposal;
    }

    public function test_pending_count_includes_project_proposal_items(): void
    {
        $admin = $this->h->seedUser('admin-inbox@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $proposal = $this->seedPendingProposal('Inbox Project Proposal');

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending-count', $token);
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $items = $body['data']['items'] ?? [];
        $this->assertSame(1, $body['data']['total'] ?? null);
        $this->assertCount(1, $items);

        $first = $items[0];
        $this->assertSame('project_proposal', $first['type'] ?? null);
        $this->assertSame($proposal->id()->value(), $first['id'] ?? null);
        $this->assertSame('Inbox Project Proposal', $first['name'] ?? null);
    }

    public function test_dismiss_project_proposal_hides_it_from_inbox(): void
    {
        $admin = $this->h->seedUser('admin-dismiss@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $proposal = $this->seedPendingProposal('Dismiss This Proposal');

        // Confirm it's visible first.
        $firstResp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending-count', $token);
        $firstBody = json_decode($firstResp->body(), true);
        $this->assertSame(1, $firstBody['data']['total'] ?? null);

        // Dismiss via the project_proposal app type.
        $dismissUrl = '/api/v1/backstage/applications/project_proposal/' . $proposal->id()->value() . '/dismiss';
        $dismissResp = $this->h->authedRequest('POST', $dismissUrl, $token);
        $this->assertSame(204, $dismissResp->status(), $dismissResp->body());

        // Now the proposal is hidden from the pending count items.
        $secondResp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending-count', $token);
        $this->assertSame(200, $secondResp->status());
        $secondBody = json_decode($secondResp->body(), true);
        $items = $secondBody['data']['items'] ?? [];
        $this->assertSame(0, $secondBody['data']['total'] ?? null);
        $this->assertCount(0, $items);
    }
}
