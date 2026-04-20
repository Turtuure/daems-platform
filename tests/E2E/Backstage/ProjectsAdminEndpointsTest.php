<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class ProjectsAdminEndpointsTest extends TestCase
{
    private KernelHarness $h;
    private string $token;
    private User $admin;

    protected function setUp(): void
    {
        $this->h     = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
        $this->admin = $this->h->seedUser('admin-proj@x.com', 'pass1234', 'admin');
        $this->token = $this->h->tokenFor($this->admin);
    }

    private function createProjectViaApi(string $title = 'E2E Project Title'): array
    {
        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/projects', $this->token, [
            'title'       => $title,
            'category'    => 'community',
            'summary'     => 'short summary text',
            'description' => 'Longer description needed to pass validation checks.',
        ]);
        $this->assertSame(201, $resp->status(), 'createProject failed: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        return $body;
    }

    public function test_create_project_returns_201_with_id_and_slug(): void
    {
        $body = $this->createProjectViaApi('Unique Create Project');
        $data = $body['data'] ?? [];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('slug', $data);
        $this->assertNotEmpty($data['id']);
        $this->assertNotEmpty($data['slug']);
    }

    public function test_list_projects_admin_returns_items(): void
    {
        $this->createProjectViaApi('List Test Project One');
        $this->createProjectViaApi('List Test Project Two');

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/projects', $this->token);
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $items = $body['items'] ?? [];
        $titles = array_column($items, 'title');
        $this->assertContains('List Test Project One', $titles);
        $this->assertContains('List Test Project Two', $titles);
        $this->assertSame(2, $body['total'] ?? null);
    }

    public function test_update_project_changes_title(): void
    {
        $created = $this->createProjectViaApi('Update Target Project');
        $id = $created['data']['id'];

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/projects/' . $id,
            $this->token,
            ['title' => 'Updated Project Title Here'],
        );
        $this->assertSame(200, $resp->status(), $resp->body());

        $listResp = $this->h->authedRequest('GET', '/api/v1/backstage/projects', $this->token);
        $body = json_decode($listResp->body(), true);
        $items = $body['items'] ?? [];
        $found = null;
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'project not in list after update');
        $this->assertSame('Updated Project Title Here', $found['title']);
    }

    public function test_change_project_status_transitions_to_active(): void
    {
        $created = $this->createProjectViaApi('Status Change Project');
        $id = $created['data']['id'];

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/projects/' . $id . '/status',
            $this->token,
            ['status' => 'active'],
        );
        $this->assertSame(200, $resp->status(), $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertSame('active', $body['data']['status'] ?? null);
    }

    public function test_change_project_status_rejects_invalid_value(): void
    {
        $created = $this->createProjectViaApi('Invalid Status Project');
        $id = $created['data']['id'];

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/projects/' . $id . '/status',
            $this->token,
            ['status' => 'not-a-real-status'],
        );
        $this->assertSame(422, $resp->status(), $resp->body());
    }

    public function test_set_project_featured_toggles_flag(): void
    {
        $created = $this->createProjectViaApi('Featured Flag Project');
        $id = $created['data']['id'];

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/projects/' . $id . '/featured',
            $this->token,
            ['featured' => true],
        );
        $this->assertSame(200, $resp->status(), $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertTrue($body['data']['featured'] ?? null);
    }

    public function test_list_proposals_returns_pending_items(): void
    {
        $this->seedPendingProposal('First Pending Proposal');
        $this->seedPendingProposal('Second Pending Proposal');

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/proposals', $this->token);
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $titles = array_column($body['items'] ?? [], 'title');
        $this->assertContains('First Pending Proposal',  $titles);
        $this->assertContains('Second Pending Proposal', $titles);
    }

    public function test_approve_proposal_creates_project_with_draft_status(): void
    {
        $proposal = $this->seedPendingProposal('Approve Me Proposal');

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/proposals/' . $proposal->id()->value() . '/approve',
            $this->token,
            ['note' => 'Great idea, approved.'],
        );
        $this->assertSame(201, $resp->status(), $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertArrayHasKey('project_id', $body['data'] ?? []);
        $this->assertArrayHasKey('slug',       $body['data'] ?? []);

        // List proposals no longer contains this one (status = approved now).
        $listResp = $this->h->authedRequest('GET', '/api/v1/backstage/proposals', $this->token);
        $listBody = json_decode($listResp->body(), true);
        $titles = array_column($listBody['items'] ?? [], 'title');
        $this->assertNotContains('Approve Me Proposal', $titles);
    }

    public function test_reject_proposal_returns_204(): void
    {
        $proposal = $this->seedPendingProposal('Reject Me Proposal');

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/proposals/' . $proposal->id()->value() . '/reject',
            $this->token,
            ['note' => 'Out of scope.'],
        );
        $this->assertSame(204, $resp->status(), $resp->body());

        $listResp = $this->h->authedRequest('GET', '/api/v1/backstage/proposals', $this->token);
        $listBody = json_decode($listResp->body(), true);
        $titles = array_column($listBody['items'] ?? [], 'title');
        $this->assertNotContains('Reject Me Proposal', $titles);
    }

    public function test_list_recent_comments_returns_project_comments(): void
    {
        $projectId = $this->seedProjectInHarness('Recent Comments Project');
        $this->seedCommentInHarness($projectId, 'Interesting comment text');

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/comments/recent', $this->token);
        $this->assertSame(200, $resp->status());
        $body = json_decode($resp->body(), true);
        $items = $body['items'] ?? [];
        $this->assertGreaterThanOrEqual(1, count($items));
        $first = $items[0];
        $this->assertArrayHasKey('comment_id',    $first);
        $this->assertArrayHasKey('project_id',    $first);
        $this->assertArrayHasKey('project_title', $first);
        $this->assertArrayHasKey('author_name',   $first);
        $this->assertArrayHasKey('content',       $first);
    }

    public function test_delete_project_comment_returns_204_and_removes_it(): void
    {
        $projectId = $this->seedProjectInHarness('Delete Comment Project');
        $commentId = $this->seedCommentInHarness($projectId, 'bad content here');

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/projects/' . $projectId . '/comments/' . $commentId . '/delete',
            $this->token,
            ['reason' => 'spam'],
        );
        $this->assertSame(204, $resp->status(), $resp->body());

        $remaining = $this->h->projects->findCommentsByProjectId($projectId);
        $this->assertCount(0, $remaining, 'comment should be gone after delete');

        $this->assertCount(1, $this->h->commentAudit->rows, 'audit row should be recorded');
        $auditRow = $this->h->commentAudit->rows[0];
        $this->assertSame($commentId, $auditRow->commentId);
        $this->assertSame('deleted',   $auditRow->action);
        $this->assertSame('spam',      $auditRow->reason);
    }

    public function test_non_admin_cannot_list_projects_admin(): void
    {
        $plainUser = $this->h->seedUser('plain@x.com', 'pass1234', 'registered');
        $plainToken = $this->h->tokenFor($plainUser);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/projects', $plainToken);
        $this->assertSame(403, $resp->status());
    }

    public function test_create_project_rejects_invalid_payload(): void
    {
        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/projects', $this->token, [
            'title'       => 'a', // too short
            'category'    => '',  // missing
            'summary'     => '',
            'description' => '',
        ]);
        $this->assertSame(422, $resp->status(), $resp->body());
    }

    // --- helpers -------------------------------------------------------

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

    private function seedProjectInHarness(string $title): string
    {
        $project = new Project(
            ProjectId::generate(),
            $this->h->testTenantId,
            strtolower(str_replace(' ', '-', $title)),
            $title,
            'community',
            'bi-folder',
            'summary long enough',
            'description long enough for tests',
            'active',
            0,
            $this->admin->id(),
            false,
            '2026-04-20 10:00:00',
        );
        $this->h->projects->save($project);
        return $project->id()->value();
    }

    private function seedCommentInHarness(string $projectId, string $content): string
    {
        $comment = new ProjectComment(
            ProjectCommentId::generate(),
            $projectId,
            UserId::generate()->value(),
            'Some Commenter',
            'SC',
            '#abc',
            $content,
            0,
            '2026-04-20 11:00:00',
        );
        $this->h->projects->saveComment($comment);
        return $comment->id()->value();
    }
}
