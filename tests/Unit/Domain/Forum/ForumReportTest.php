<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Forum;

use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportId;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ForumReportTest extends TestCase
{
    public function test_valid_construction(): void
    {
        $r = new ForumReport(
            ForumReportId::fromString('01958000-0000-7000-8000-000000000011'),
            TenantId::fromString('01958000-0000-7000-8000-000000000022'),
            ForumReport::TARGET_POST,
            'p-1',
            'u-1',
            'spam',
            null,
            ForumReport::STATUS_OPEN,
            null,
            null,
            null,
            null,
            '2026-04-20 10:00:00',
        );
        self::assertSame('spam', $r->reasonCategory());
        self::assertSame(ForumReport::STATUS_OPEN, $r->status());
    }

    public function test_reason_categories_constant_covers_enum(): void
    {
        self::assertContains('hate_speech', ForumReport::REASON_CATEGORIES);
        self::assertCount(6, ForumReport::REASON_CATEGORIES);
    }
}
