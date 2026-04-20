<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdmin;
use Daems\Application\Backstage\Forum\ListForumReportsForAdmin\ListForumReportsForAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\AggregatedForumReport;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use PHPUnit\Framework\TestCase;

final class ListForumReportsForAdminTest extends TestCase
{
    private const TENANT_ID   = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID    = '01958000-0000-7000-8000-000000000a01';
    private const REGISTERED  = '01958000-0000-7000-8000-000000000a02';
    private const POST_ID     = '01958000-0000-7000-8000-0000000a0001';
    private const TOPIC_ID    = '01958000-0000-7000-8000-000000010001';
    private const REPORTER_1  = '01958000-0000-7000-8000-000000000b01';
    private const REPORTER_2  = '01958000-0000-7000-8000-000000000b02';
    private const REPORTER_3  = '01958000-0000-7000-8000-000000000b03';

    public function test_returns_aggregated_rows_for_admin(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $reports = new InMemoryForumReportRepository();
        $forum   = new InMemoryForumRepository();

        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_2, 'spam');
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_3, 'off_topic');

        $uc  = new ListForumReportsForAdmin($reports, $forum);
        $out = $uc->execute(new ListForumReportsForAdminInput($admin, [], 50));

        self::assertCount(2, $out->items);

        $post = $this->findByTargetType($out->items, 'post');
        self::assertNotNull($post);
        self::assertSame(2, $post->reportCount);
        self::assertSame(['spam' => 2], $post->reasonCounts);
        self::assertSame(self::POST_ID, $post->targetId);

        $topic = $this->findByTargetType($out->items, 'topic');
        self::assertNotNull($topic);
        self::assertSame(1, $topic->reportCount);
        self::assertSame(['off_topic' => 1], $topic->reasonCounts);
    }

    public function test_filters_by_target_type(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $reports = new InMemoryForumReportRepository();
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'topic', self::TOPIC_ID, self::REPORTER_2, 'spam');

        $out = (new ListForumReportsForAdmin($reports, new InMemoryForumRepository()))
            ->execute(new ListForumReportsForAdminInput($admin, ['target_type' => 'topic'], 50));

        self::assertCount(1, $out->items);
        self::assertSame('topic', $out->items[0]->targetType);
        self::assertSame(self::TOPIC_ID, $out->items[0]->targetId);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::registeredInTenant(self::REGISTERED, $tenant);

        $this->expectException(ForbiddenException::class);

        (new ListForumReportsForAdmin(new InMemoryForumReportRepository(), new InMemoryForumRepository()))
            ->execute(new ListForumReportsForAdminInput($member, [], 50));
    }

    /**
     * @param list<AggregatedForumReport> $items
     */
    private function findByTargetType(array $items, string $targetType): ?AggregatedForumReport
    {
        foreach ($items as $a) {
            if ($a->targetType === $targetType) {
                return $a;
            }
        }
        return null;
    }
}
