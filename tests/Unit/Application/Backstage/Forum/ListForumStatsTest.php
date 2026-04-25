<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumStats\ListForumStats;
use Daems\Application\Backstage\Forum\ListForumStats\ListForumStatsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use PHPUnit\Framework\TestCase;

final class ListForumStatsTest extends TestCase
{
    private const TENANT_ID = '019d0000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '019d0000-0000-7000-8000-000000000a01';
    private const USER_ID   = '019d0000-0000-7000-8000-000000000a02';

    public function test_returns_payload_for_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $forumRepo  = new InMemoryForumRepository();
        $reportRepo = new InMemoryForumReportRepository();
        $auditRepo  = new InMemoryForumModerationAuditRepository();

        $uc  = new ListForumStats($forumRepo, $reportRepo, $auditRepo);
        $out = $uc->execute(new ListForumStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(0, $out->stats['open_reports']['value']);
        self::assertSame(0, $out->stats['topics']['value']);
        self::assertSame(0, $out->stats['categories']['value']);
        self::assertSame(0, $out->stats['mod_actions']['value']);
        self::assertSame([], $out->stats['categories']['sparkline']);
        self::assertCount(30, $out->stats['mod_actions']['sparkline']);
        self::assertCount(0, $out->recentAudit);
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $member   = ActingUserFactory::registeredInTenant(self::USER_ID, $tenantId);

        $this->expectException(ForbiddenException::class);

        $uc = new ListForumStats(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        );
        $uc->execute(new ListForumStatsInput(acting: $member, tenantId: $tenantId));
    }
}
