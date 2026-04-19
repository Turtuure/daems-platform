<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F004_UnauthProjectMutationTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
    }

    private function seedProject(?UserId $ownerId = null, string $slug = 'my-project'): Project
    {
        $project = new Project(
            ProjectId::generate(),
            $this->h->testTenantId,
            $slug,
            'Title',
            'research',
            'icon',
            'summary',
            'desc',
            'active',
            0,
            $ownerId,
        );
        $this->h->projects->save($project);
        return $project;
    }

    public function testAnonymousArchiveReturns401(): void
    {
        $this->seedProject();
        $resp = $this->h->request('POST', '/api/v1/projects/my-project/archive');
        $this->assertSame(401, $resp->status());
    }

    public function testNonOwnerTokenReturns403(): void
    {
        $owner = $this->h->seedUser('owner@x.com');
        $this->seedProject($owner->id());
        $attacker = $this->h->seedUser('attacker@x.com');
        $token = $this->h->tokenFor($attacker);

        $resp = $this->h->authedRequest('POST', '/api/v1/projects/my-project/archive', $token);
        $this->assertSame(403, $resp->status());
    }

    public function testOwnerCanArchive(): void
    {
        $owner = $this->h->seedUser('owner@x.com');
        $this->seedProject($owner->id());
        $token = $this->h->tokenFor($owner);

        $resp = $this->h->authedRequest('POST', '/api/v1/projects/my-project/archive', $token);
        $this->assertSame(200, $resp->status());
        $this->assertSame('archived', $this->h->projects->findBySlugForTenant('my-project', $this->h->testTenantId)->status());
    }

    public function testAdminCanArchiveAnyProject(): void
    {
        $owner = $this->h->seedUser('owner@x.com');
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $this->seedProject($owner->id());
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/projects/my-project/archive', $token);
        $this->assertSame(200, $resp->status());
    }

    public function testLegacyNullOwnerForbiddenForNonAdmin(): void
    {
        $this->seedProject(null);
        $u = $this->h->seedUser('u@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('POST', '/api/v1/projects/my-project/archive', $token);
        $this->assertSame(403, $resp->status());
    }

    public function testLegacyNullOwnerAllowedForAdmin(): void
    {
        $this->seedProject(null);
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/projects/my-project/archive', $token);
        $this->assertSame(200, $resp->status());
    }

    public function testCreateSetsOwnerIdToActingUser(): void
    {
        $u = $this->h->seedUser('u@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('POST', '/api/v1/projects', $token, [
            'title' => 'New Project',
            'category' => 'research',
            'summary' => 's',
            'description' => 'd',
        ]);

        $this->assertSame(201, $resp->status());
        // Fetch the saved project from the fake repo
        $saved = array_values($this->h->projects->bySlug)[0];
        $this->assertNotNull($saved->ownerId());
        $this->assertSame($u->id()->value(), $saved->ownerId()->value());
    }
}
