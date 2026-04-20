<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use InvalidArgumentException;
use Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLock;
use Daems\Application\Backstage\Forum\ResolveForumReportByLock\ResolveForumReportByLockInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class ResolveForumReportByLockTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID  = '01958000-0000-7000-8000-000000000a02';
    private const TOPIC_ID   = '01958000-0000-7000-8000-000000010001';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const REPORTER_1 = '01958000-0000-7000-8000-000000000b01';
    private const REPORTER_2 = '01958000-0000-7000-8000-000000000b02';

    public function test_locks_topic_and_resolves_and_audits(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum   = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_ID, 'topic-one', 'Topic One', pinned: false, locked: false);

        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_2, 'harassment');

        $uc = new ResolveForumReportByLock($forum, $reports, $audit);
        $uc->execute(new ResolveForumReportByLockInput($admin, 'topic', self::TOPIC_ID, 'locking bad topic'));

        // Topic is now locked
        $topic = $forum->findTopicByIdForTenant(self::TOPIC_ID, $tenant);
        self::assertNotNull($topic);
        self::assertTrue($topic->locked());

        // All open reports for this target resolved with action=locked
        self::assertCount(2, $reports->reports);
        foreach ($reports->reports as $r) {
            self::assertSame(ForumReport::STATUS_RESOLVED, $r->status());
            self::assertSame('locked', $r->resolutionAction());
            self::assertSame(self::ADMIN_ID, $r->resolvedBy());
            self::assertSame('locking bad topic', $r->resolutionNote());
        }

        // Audit row present
        self::assertCount(1, $audit->rows);
        $entry = $audit->rows[0];
        self::assertSame('locked', $entry->action());
        self::assertSame('topic', $entry->targetType());
        self::assertSame(self::TOPIC_ID, $entry->targetId());
        self::assertSame(['locked' => false], $entry->originalPayload());
        self::assertSame(['locked' => true], $entry->newPayload());
        self::assertSame('locking bad topic', $entry->reason());
        self::assertSame(self::ADMIN_ID, $entry->performedBy());
    }

    public function test_post_target_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum   = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'a post');

        $uc = new ResolveForumReportByLock($forum, $reports, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot_lock_post');

        $uc->execute(new ResolveForumReportByLockInput($admin, 'post', self::POST_ID));
    }

    public function test_unknown_topic_throws_not_found(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new ResolveForumReportByLock(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('topic_not_found');

        $uc->execute(new ResolveForumReportByLockInput($admin, 'topic', self::TOPIC_ID));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $forum = new InMemoryForumRepository();
        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_ID);

        $uc = new ResolveForumReportByLock(
            $forum,
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new ResolveForumReportByLockInput($member, 'topic', self::TOPIC_ID));
    }
}
