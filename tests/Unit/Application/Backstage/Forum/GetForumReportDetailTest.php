<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetail;
use Daems\Application\Backstage\Forum\GetForumReportDetail\GetForumReportDetailInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class GetForumReportDetailTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const REPORTER_1 = '01958000-0000-7000-8000-000000000b01';
    private const REPORTER_2 = '01958000-0000-7000-8000-000000000b02';

    public function test_returns_aggregated_plus_raw_plus_target_content(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $reports = new InMemoryForumReportRepository();
        $forum   = new InMemoryForumRepository();
        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'offending content');

        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_2, 'harassment');

        $uc  = new GetForumReportDetail($reports, $forum);
        $out = $uc->execute(new GetForumReportDetailInput($admin, 'post', self::POST_ID));

        self::assertSame(2, $out->aggregated->reportCount);
        self::assertSame(self::POST_ID, $out->aggregated->targetId);
        self::assertCount(2, $out->rawReports);
        self::assertArrayHasKey('spam', $out->aggregated->reasonCounts);
        self::assertArrayHasKey('harassment', $out->aggregated->reasonCounts);
        self::assertSame(1, $out->aggregated->reasonCounts['spam']);
        self::assertSame(1, $out->aggregated->reasonCounts['harassment']);
        self::assertLessThanOrEqual($out->aggregated->latestCreatedAt, $out->aggregated->earliestCreatedAt);

        self::assertSame('offending content', $out->targetContent['content'] ?? null);
        self::assertArrayHasKey('author', $out->targetContent);
        self::assertArrayHasKey('created_at', $out->targetContent);
    }

    public function test_unknown_target_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new GetForumReportDetail(new InMemoryForumReportRepository(), new InMemoryForumRepository());

        $this->expectException(NotFoundException::class);
        $uc->execute(new GetForumReportDetailInput($admin, 'post', self::POST_ID));
    }
}
