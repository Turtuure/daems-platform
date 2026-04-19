<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F005_ForumRoleImpersonationTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-19T12:00:00Z'));
        $cat = new ForumCategory(ForumCategoryId::generate(), $this->h->testTenantId, 'general', 'General', 'chat', 'desc', 1);
        $this->h->forum->saveCategory($cat);

        $topic = new ForumTopic(
            ForumTopicId::generate(),
            $this->h->testTenantId,
            $cat->id()->value(),
            null,
            'existing-topic',
            'Existing',
            'Admin',
            'AD',
            '#000',
            false,
            0,
            0,
            '2026-04-01',
            'Admin',
            '2026-04-01',
        );
        $this->h->forum->saveTopic($topic);
    }

    public function testNonAdminCannotPostWithAdministratorRole(): void
    {
        $u = $this->h->seedUser('attacker@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('POST', '/api/v1/forum/topics/existing-topic/posts', $token, [
            'content'     => 'OFFICIAL NOTICE: send funds to attacker@evil.com',
            'role'        => 'Administrator',
            'role_class'  => 'role-admin',
            'joined_text' => 'Site founder since 2020',
            'author_name' => 'Site Administrator',
            'user_id'     => 'some-other-id',
        ]);

        $this->assertSame(201, $resp->status());

        $storedPost = $this->h->forum->lastPost();
        $this->assertNotSame('Administrator', $storedPost->role());
        $this->assertNotSame('role-admin', $storedPost->roleClass());
        $this->assertNotSame('Site Administrator', $storedPost->authorName());
        $this->assertSame($u->id()->value(), $storedPost->userId());
        $this->assertSame($u->name(), $storedPost->authorName());
    }

    public function testAdminUserDoesGetAdministratorBadge(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/forum/topics/existing-topic/posts', $token, [
            'content' => 'Hello from a real admin',
        ]);

        $this->assertSame(201, $resp->status());

        $storedPost = $this->h->forum->lastPost();
        $this->assertSame('Administrator', $storedPost->role());
        $this->assertSame('role-admin', $storedPost->roleClass());
    }

    public function testAnonymousPostReturns401(): void
    {
        $resp = $this->h->request('POST', '/api/v1/forum/topics/existing-topic/posts', [
            'content' => 'hi',
        ]);
        $this->assertSame(401, $resp->status());
    }
}
