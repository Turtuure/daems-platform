<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReport;
use Daems\Application\Backstage\Forum\DismissForumReport\DismissForumReportInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use PHPUnit\Framework\TestCase;

final class DismissForumReportTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000c01';
    private const MEMBER_ID  = '01958000-0000-7000-8000-000000000c02';
    private const TOPIC_ID   = '01958000-0000-7000-8000-000000020001';
    private const POST_ID    = '01958000-0000-7000-8000-0000000b0001';
    private const REPORTER_1 = '01958000-0000-7000-8000-000000000d01';
    private const REPORTER_2 = '01958000-0000-7000-8000-000000000d02';
    private const REPORTER_3 = '01958000-0000-7000-8000-000000000d03';

    public function test_dismisses_all_open_reports_for_target_without_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_2, 'harassment');
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_3, 'other');

        $uc = new DismissForumReport($reports);
        $uc->execute(new DismissForumReportInput($admin, 'topic', self::TOPIC_ID, 'perusteeton'));

        // All 3 reports are now dismissed
        self::assertCount(3, $reports->reports);
        foreach ($reports->reports as $r) {
            self::assertSame(ForumReport::STATUS_DISMISSED, $r->status());
            self::assertSame('dismissed', $r->resolutionAction());
            self::assertSame(self::ADMIN_ID, $r->resolvedBy());
            self::assertSame('perusteeton', $r->resolutionNote());
        }

        // No audit row written — status on reports is the trail
        self::assertCount(0, $audit->rows);
    }

    public function test_already_dismissed_is_idempotent(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_2, 'harassment');

        $uc = new DismissForumReport($reports);

        // First call dismisses
        $uc->execute(new DismissForumReportInput($admin, 'post', self::POST_ID, 'first'));
        // Second call is a no-op — no exception, reports still dismissed
        $uc->execute(new DismissForumReportInput($admin, 'post', self::POST_ID, 'second'));

        self::assertCount(2, $reports->reports);
        foreach ($reports->reports as $r) {
            self::assertSame(ForumReport::STATUS_DISMISSED, $r->status());
            self::assertSame('dismissed', $r->resolutionAction());
            // note from first call preserved because second call skips non-open rows
            self::assertSame('first', $r->resolutionNote());
        }

        self::assertCount(0, $audit->rows);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $reports = new InMemoryForumReportRepository();

        $uc = new DismissForumReport($reports);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new DismissForumReportInput($member, 'topic', self::TOPIC_ID));
    }

    public function test_no_open_reports_is_noop(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        $uc = new DismissForumReport($reports);

        // Calling with zero open reports returns without throwing
        $uc->execute(new DismissForumReportInput($admin, 'topic', self::TOPIC_ID, 'none'));

        self::assertCount(0, $reports->reports);
        self::assertCount(0, $audit->rows);
    }
}
