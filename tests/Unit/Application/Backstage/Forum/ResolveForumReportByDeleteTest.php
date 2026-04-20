<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDelete;
use Daems\Application\Backstage\Forum\ResolveForumReportByDelete\ResolveForumReportByDeleteInput;
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

final class ResolveForumReportByDeleteTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID  = '01958000-0000-7000-8000-000000000a02';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const POST_ID_2  = '01958000-0000-7000-8000-0000000a0002';
    private const TOPIC_ID   = '01958000-0000-7000-8000-000000010001';
    private const REPORTER_1 = '01958000-0000-7000-8000-000000000b01';
    private const REPORTER_2 = '01958000-0000-7000-8000-000000000b02';
    private const MISSING_ID = '01958000-0000-7000-8000-00000000dead';

    public function test_deletes_post_and_resolves_reports_and_writes_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum   = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'bad content');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_2, 'spam');

        $uc = new ResolveForumReportByDelete($forum, $reports, $audit);
        $uc->execute(new ResolveForumReportByDeleteInput($admin, 'post', self::POST_ID, 'Spam chain'));

        // Post is gone
        self::assertNull($forum->findPostByIdForTenant(self::POST_ID, $tenant));

        // All open reports for this target resolved with action=deleted
        self::assertCount(2, $reports->reports);
        foreach ($reports->reports as $r) {
            self::assertSame(ForumReport::STATUS_RESOLVED, $r->status());
            self::assertSame('deleted', $r->resolutionAction());
            self::assertSame(self::ADMIN_ID, $r->resolvedBy());
            self::assertSame('Spam chain', $r->resolutionNote());
        }

        // Audit row with originalPayload snapshot
        $entries = $audit->listRecentForTenant($tenant);
        self::assertCount(1, $entries);
        self::assertSame('deleted', $entries[0]->action());
        self::assertSame('post', $entries[0]->targetType());
        self::assertSame(self::POST_ID, $entries[0]->targetId());
        self::assertSame(self::ADMIN_ID, $entries[0]->performedBy());
        self::assertSame('Spam chain', $entries[0]->reason());
        $payload = $entries[0]->originalPayload();
        self::assertNotNull($payload);
        self::assertSame('bad content', $payload['content'] ?? null);
        self::assertArrayHasKey('author_id', $payload);
        self::assertArrayHasKey('topic_id', $payload);
        self::assertArrayHasKey('created_at', $payload);
    }

    public function test_deletes_topic_cascade(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum   = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedTopicWithPosts($forum, $tenant, self::TOPIC_ID, [
            [self::POST_ID],
            [self::POST_ID_2],
        ]);
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_1, 'off_topic');

        $uc = new ResolveForumReportByDelete($forum, $reports, $audit);
        $uc->execute(new ResolveForumReportByDeleteInput($admin, 'topic', self::TOPIC_ID, null));

        // Topic AND its posts are gone (cascade)
        self::assertNull($forum->findTopicByIdForTenant(self::TOPIC_ID, $tenant));
        self::assertNull($forum->findPostByIdForTenant(self::POST_ID, $tenant));
        self::assertNull($forum->findPostByIdForTenant(self::POST_ID_2, $tenant));

        // Report resolved
        self::assertCount(1, $reports->reports);
        $first = array_values($reports->reports)[0];
        self::assertSame(ForumReport::STATUS_RESOLVED, $first->status());
        self::assertSame('deleted', $first->resolutionAction());

        // Audit recorded
        $entries = $audit->listRecentForTenant($tenant);
        self::assertCount(1, $entries);
        self::assertSame('topic', $entries[0]->targetType());
        self::assertSame(self::TOPIC_ID, $entries[0]->targetId());
        $payload = $entries[0]->originalPayload();
        self::assertNotNull($payload);
        self::assertArrayHasKey('title', $payload);
        self::assertArrayHasKey('category_id', $payload);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        (new ResolveForumReportByDelete(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        ))->execute(new ResolveForumReportByDeleteInput($member, 'post', self::POST_ID, null));
    }

    public function test_unknown_target_throws_not_found(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('post_not_found');

        (new ResolveForumReportByDelete(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        ))->execute(new ResolveForumReportByDeleteInput($admin, 'post', self::MISSING_ID, null));
    }
}
