<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\SidebarEntry;
use PHPUnit\Framework\TestCase;

final class SidebarEntryTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $entry = new SidebarEntry(
            group: 'content',
            order: 20,
            icon: 'calendar',
            href: '/backstage/events',
        );
        self::assertSame('content', $entry->group());
        self::assertSame(20, $entry->order());
        self::assertSame('calendar', $entry->icon());
        self::assertSame('/backstage/events', $entry->href());
    }

    public function test_allows_zero_order(): void
    {
        $entry = new SidebarEntry(group: 'g', order: 0, icon: 'i', href: '/x');
        self::assertSame(0, $entry->order());
    }

    public function test_rejects_negative_order(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/order/i');
        new SidebarEntry(group: 'g', order: -1, icon: 'i', href: '/x');
    }

    public function test_rejects_empty_group(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/group/i');
        new SidebarEntry(group: '', order: 0, icon: 'i', href: '/x');
    }

    public function test_rejects_empty_icon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/icon/i');
        new SidebarEntry(group: 'g', order: 0, icon: '', href: '/x');
    }

    public function test_rejects_href_without_leading_slash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/href/i');
        new SidebarEntry(group: 'g', order: 0, icon: 'i', href: 'backstage/x');
    }

    public function test_rejects_empty_href(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/href/i');
        new SidebarEntry(group: 'g', order: 0, icon: 'i', href: '');
    }
}
