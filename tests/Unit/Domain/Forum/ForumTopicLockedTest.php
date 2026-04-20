<?php
declare(strict_types=1);
namespace Daems\Tests\Unit\Domain\Forum;

use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ForumTopicLockedTest extends TestCase
{
    public function test_default_locked_is_false(): void
    {
        $t = new ForumTopic(
            ForumTopicId::fromString('01958000-0000-7000-8000-000000000011'),
            TenantId::fromString('01958000-0000-7000-8000-000000000022'),
            'cat',
            null,
            'slug',
            'Title',
            'Author',
            'AU',
            null,
            false,
            0,
            0,
            '2026-01-01 00:00:00',
            '',
            '2026-01-01 00:00:00',
        );
        self::assertFalse($t->locked());
    }

    public function test_locked_true_reflected(): void
    {
        $t = new ForumTopic(
            ForumTopicId::fromString('01958000-0000-7000-8000-000000000011'),
            TenantId::fromString('01958000-0000-7000-8000-000000000022'),
            'cat', null, 'slug', 'Title', 'A', 'A', null, false, 0, 0,
            '2026-01-01 00:00:00', '', '2026-01-01 00:00:00', true,
        );
        self::assertTrue($t->locked());
    }
}
