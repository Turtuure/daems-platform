<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F007_IdentitySpoofingTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
    }

    private function seedProject(?UserId $ownerId, string $slug = 'p'): void
    {
        $this->h->projects->save(new Project(
            ProjectId::generate(),
            $slug,
            'T',
            'c',
            'i',
            's',
            'd',
            'active',
            0,
            $ownerId,
        ));
    }

    public function testCommentUserIdDerivedFromActingUser(): void
    {
        $alice = $this->h->seedUser('alice@x.com');
        $bob = $this->h->seedUser('bob@x.com');
        $bobToken = $this->h->tokenFor($bob);
        $this->seedProject($bob->id(), 'p');

        // Bob posts a comment but tries to attribute it to Alice
        $resp = $this->h->authedRequest('POST', '/api/v1/projects/p/comments', $bobToken, [
            'user_id'     => $alice->id()->value(),
            'author_name' => 'Alice (Victim)',
            'content'     => 'I endorse this attacker',
        ]);

        $this->assertSame(201, $resp->status());
        $stored = $this->h->projects->comments[0];
        $this->assertSame($bob->id()->value(), $stored->userId());
        $this->assertSame($bob->name(), $stored->authorName());
        $this->assertNotSame('Alice (Victim)', $stored->authorName());
    }

    public function testJoinAndLeaveUseActingUserNotBodyUserId(): void
    {
        $alice = $this->h->seedUser('alice@x.com');
        $bob = $this->h->seedUser('bob@x.com');
        $bobToken = $this->h->tokenFor($bob);
        $this->seedProject(null, 'p');

        // Bob tries to make it look like Alice joined
        $resp = $this->h->authedRequest('POST', '/api/v1/projects/p/join', $bobToken, [
            'user_id' => $alice->id()->value(),
        ]);
        $this->assertSame(200, $resp->status());

        $project = $this->h->projects->findBySlug('p');
        $this->assertTrue($this->h->projects->isParticipant($project->id()->value(), $bob->id()->value()));
        $this->assertFalse($this->h->projects->isParticipant($project->id()->value(), $alice->id()->value()));

        // Now Alice really joins
        $aliceToken = $this->h->tokenFor($alice);
        $this->h->authedRequest('POST', '/api/v1/projects/p/join', $aliceToken);
        $this->assertTrue($this->h->projects->isParticipant($project->id()->value(), $alice->id()->value()));

        // Bob attempts to evict Alice by specifying her user_id in leave
        $this->h->authedRequest('POST', '/api/v1/projects/p/leave', $bobToken, [
            'user_id' => $alice->id()->value(),
        ]);
        $this->assertFalse($this->h->projects->isParticipant($project->id()->value(), $bob->id()->value()));
        $this->assertTrue($this->h->projects->isParticipant($project->id()->value(), $alice->id()->value()));
    }

    public function testProposalDerivesIdentityFromActingUser(): void
    {
        $alice = $this->h->seedUser('alice@x.com');
        $bob = $this->h->seedUser('bob@x.com');
        $bobToken = $this->h->tokenFor($bob);

        $resp = $this->h->authedRequest('POST', '/api/v1/project-proposals', $bobToken, [
            'user_id'      => $alice->id()->value(),
            'author_name'  => 'Alice',
            'author_email' => 'alice@x.com',
            'title'        => 'Shady proposal',
            'category'     => 'research',
            'summary'      => 's',
            'description'  => 'd',
        ]);

        $this->assertSame(201, $resp->status());
        $stored = $this->h->proposals->proposals[0];
        $this->assertSame($bob->id()->value(), $stored->userId());
        $this->assertSame($bob->email(), $stored->authorEmail());
        $this->assertSame($bob->name(), $stored->authorName());
    }
}
