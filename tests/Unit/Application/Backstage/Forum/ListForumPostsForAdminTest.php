<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdmin;
use Daems\Application\Backstage\Forum\ListForumPostsForAdmin\ListForumPostsForAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class ListForumPostsForAdminTest extends TestCase
{
    private const TENANT_ID = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID = '01958000-0000-7000-8000-000000000a02';
    private const POST_A    = '01958000-0000-7000-8000-0000000a0001';
    private const POST_B    = '01958000-0000-7000-8000-0000000a0002';

    public function test_returns_recent_posts_for_admin(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $forum  = new InMemoryForumRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_A, null, 'first');
        ForumSeed::seedPost($forum, $tenant, self::POST_B, null, 'second');

        $uc  = new ListForumPostsForAdmin($forum);
        $out = $uc->execute(new ListForumPostsForAdminInput($admin, 50, []));

        self::assertCount(2, $out->posts);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $uc = new ListForumPostsForAdmin(new InMemoryForumRepository());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new ListForumPostsForAdminInput($member));
    }
}
