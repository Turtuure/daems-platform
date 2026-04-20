<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Forum;

use Daems\Application\Forum\CreateForumPost\CreateForumPost;
use Daems\Application\Forum\CreateForumPost\CreateForumPostInput;
use Daems\Domain\Forum\TopicLockedException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class CreateForumPostTest extends TestCase
{
    private const TENANT_ID = '11111111-1111-7111-8111-111111111111';
    private const USER_ID   = '01958000-0000-7000-8000-000000000a01';
    private const TOPIC_ID  = '01958000-0000-7000-8000-000000010001';

    public function test_locked_topic_rejects_new_post(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $user   = ActingUserFactory::memberInTenant(self::USER_ID, $tenant);
        $forum  = new InMemoryForumRepository();
        ForumSeed::seedTopic(
            $forum,
            $tenant,
            self::TOPIC_ID,
            slug: 't-1-slug',
            locked: true,
        );

        $uc = new CreateForumPost($forum, new InMemoryUserRepository());

        $this->expectException(TopicLockedException::class);
        $uc->execute(new CreateForumPostInput($user, 't-1-slug', 'content'));
    }
}
