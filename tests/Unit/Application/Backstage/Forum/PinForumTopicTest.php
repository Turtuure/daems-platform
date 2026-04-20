<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopic;
use Daems\Application\Backstage\Forum\PinForumTopic\PinForumTopicInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class PinForumTopicTest extends TestCase
{
    private const TENANT_ID = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID = '01958000-0000-7000-8000-000000000a02';
    private const TOPIC_ID  = '01958000-0000-7000-8000-000000010001';

    public function test_pins_topic_and_writes_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_ID, 'topic-one', 'Topic One', pinned: false, locked: false);

        $uc = new PinForumTopic($forum, $audit);
        $uc->execute(new PinForumTopicInput($admin, self::TOPIC_ID));

        $topic = $forum->findTopicByIdForTenant(self::TOPIC_ID, $tenant);
        self::assertNotNull($topic);
        self::assertTrue($topic->pinned());

        self::assertCount(1, $audit->rows);
        $entry = $audit->rows[0];
        self::assertSame(ForumModerationAuditEntry::ACTION_PINNED, $entry->action());
        self::assertSame('topic', $entry->targetType());
        self::assertSame(self::TOPIC_ID, $entry->targetId());
        self::assertSame(['pinned' => false], $entry->originalPayload());
        self::assertSame(['pinned' => true], $entry->newPayload());
        self::assertSame(self::ADMIN_ID, $entry->performedBy());
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $forum = new InMemoryForumRepository();
        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_ID);

        $uc = new PinForumTopic($forum, new InMemoryForumModerationAuditRepository());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new PinForumTopicInput($member, self::TOPIC_ID));
    }

    public function test_unknown_topic_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new PinForumTopic(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('topic_not_found');

        $uc->execute(new PinForumTopicInput($admin, self::TOPIC_ID));
    }
}
