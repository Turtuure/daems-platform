<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdmin;
use Daems\Application\Backstage\Forum\ListForumTopicsForAdmin\ListForumTopicsForAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class ListForumTopicsForAdminTest extends TestCase
{
    private const TENANT_ID = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID = '01958000-0000-7000-8000-000000000a02';
    private const TOPIC_A   = '01958000-0000-7000-8000-000000010001';
    private const TOPIC_B   = '01958000-0000-7000-8000-000000010002';

    public function test_returns_recent_topics_for_admin(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $forum  = new InMemoryForumRepository();

        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_A, 'topic-a', 'Topic A');
        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_B, 'topic-b', 'Topic B');

        $uc  = new ListForumTopicsForAdmin($forum);
        $out = $uc->execute(new ListForumTopicsForAdminInput($admin, 50, []));

        self::assertCount(2, $out->topics);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $uc = new ListForumTopicsForAdmin(new InMemoryForumRepository());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new ListForumTopicsForAdminInput($member));
    }
}
