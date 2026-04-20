<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarn;
use Daems\Application\Backstage\Forum\ResolveForumReportByWarn\ResolveForumReportByWarnInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\Fake\InMemoryForumUserWarningRepository;
use Daems\Tests\Support\ForumSeed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResolveForumReportByWarnTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const TOPIC_ID   = '01958000-0000-7000-8000-000000010002';
    private const AUTHOR_ID  = '01958000-0000-7000-8000-0000000b0001';
    private const AUTHOR_B   = '01958000-0000-7000-8000-0000000b0002';
    private const REPORTER_1 = '01958000-0000-7000-8000-000000000c01';
    private const REPORTER_2 = '01958000-0000-7000-8000-000000000c02';

    public function test_warns_post_author_and_resolves_and_audits(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum    = new InMemoryForumRepository();
        $reports  = new InMemoryForumReportRepository();
        $warnings = new InMemoryForumUserWarningRepository();
        $audit    = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'bad post', self::AUTHOR_ID);
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_2, 'harassment');

        $uc = new ResolveForumReportByWarn($forum, $reports, $warnings, $audit);
        $uc->execute(new ResolveForumReportByWarnInput($admin, 'post', self::POST_ID, 'Please read the rules.'));

        // 1) Warning was recorded against the post's author, scoped to the tenant.
        $warned = $warnings->listForUserForTenant(self::AUTHOR_ID, $tenant);
        self::assertCount(1, $warned);
        self::assertSame(self::AUTHOR_ID, $warned[0]->userId());
        self::assertSame('Please read the rules.', $warned[0]->reason());
        self::assertNull($warned[0]->relatedReportId());
        self::assertSame(self::ADMIN_ID, $warned[0]->issuedBy());
        self::assertTrue($warned[0]->tenantId()->equals($tenant));

        // 2) All open reports for the target are resolved with action=warned.
        foreach ($reports->reports as $r) {
            self::assertSame(ForumReport::STATUS_RESOLVED, $r->status());
            self::assertSame('warned', $r->resolutionAction());
        }

        // 3) Audit row recorded with action=warned and author_id in original_payload.
        self::assertCount(1, $audit->rows);
        $row = $audit->rows[0];
        self::assertSame('warned', $row->action());
        self::assertSame('post', $row->targetType());
        self::assertSame(self::POST_ID, $row->targetId());
        self::assertSame(['author_id' => self::AUTHOR_ID], $row->originalPayload());
        self::assertNull($row->newPayload());
        self::assertSame('Please read the rules.', $row->reason());
        self::assertSame(self::ADMIN_ID, $row->performedBy());
    }

    public function test_warns_topic_author_when_target_is_topic(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum    = new InMemoryForumRepository();
        $reports  = new InMemoryForumReportRepository();
        $warnings = new InMemoryForumUserWarningRepository();
        $audit    = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedTopic(
            $forum,
            $tenant,
            self::TOPIC_ID,
            'topic-bad',
            'Bad Topic',
            false,
            false,
            null,
            self::AUTHOR_B,
        );
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_1, 'spam');

        $uc = new ResolveForumReportByWarn($forum, $reports, $warnings, $audit);
        $uc->execute(new ResolveForumReportByWarnInput($admin, 'topic', self::TOPIC_ID, 'Stay on topic.'));

        $warned = $warnings->listForUserForTenant(self::AUTHOR_B, $tenant);
        self::assertCount(1, $warned);
        self::assertSame(self::AUTHOR_B, $warned[0]->userId());
        self::assertSame('Stay on topic.', $warned[0]->reason());

        self::assertCount(1, $audit->rows);
        $row = $audit->rows[0];
        self::assertSame('warned', $row->action());
        self::assertSame('topic', $row->targetType());
        self::assertSame(self::TOPIC_ID, $row->targetId());
        self::assertSame(['author_id' => self::AUTHOR_B], $row->originalPayload());
    }

    public function test_no_author_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum    = new InMemoryForumRepository();
        $reports  = new InMemoryForumReportRepository();
        $warnings = new InMemoryForumUserWarningRepository();
        $audit    = new InMemoryForumModerationAuditRepository();

        // Seed a post with a null author (legacy/anonymous content).
        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'anon content', null);
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');

        $uc = new ResolveForumReportByWarn($forum, $reports, $warnings, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no_author_to_warn');
        $uc->execute(new ResolveForumReportByWarnInput($admin, 'post', self::POST_ID, 'note'));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::ADMIN_ID, $tenant);

        $uc = new ResolveForumReportByWarn(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumUserWarningRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(ForbiddenException::class);
        $uc->execute(new ResolveForumReportByWarnInput($member, 'post', self::POST_ID, 'note'));
    }

    public function test_unknown_target_throws_not_found(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new ResolveForumReportByWarn(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumUserWarningRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $uc->execute(new ResolveForumReportByWarnInput($admin, 'post', self::POST_ID, 'note'));
    }
}
