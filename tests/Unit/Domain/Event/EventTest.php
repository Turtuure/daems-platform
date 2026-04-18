<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Event;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    private function makeEvent(array $overrides = []): Event
    {
        return new Event(
            $overrides['id']          ?? EventId::generate(),
            $overrides['slug']        ?? 'annual-meeting-2025',
            $overrides['title']       ?? 'Annual Meeting',
            $overrides['type']        ?? 'general',
            $overrides['date']        ?? '2025-09-01',
            $overrides['time']        ?? '18:00',
            $overrides['location']    ?? 'Helsinki',
            $overrides['online']      ?? false,
            $overrides['description'] ?? 'Yearly general assembly',
            $overrides['heroImage']   ?? null,
            $overrides['gallery']     ?? [],
        );
    }

    public function testGettersReturnConstructorValues(): void
    {
        $id    = EventId::generate();
        $event = $this->makeEvent(['id' => $id, 'slug' => 'test-event', 'title' => 'Test Event']);

        $this->assertSame($id, $event->id());
        $this->assertSame('test-event', $event->slug());
        $this->assertSame('Test Event', $event->title());
    }

    public function testOnlineFlagIsStored(): void
    {
        $online  = $this->makeEvent(['online' => true]);
        $offline = $this->makeEvent(['online' => false]);

        $this->assertTrue($online->online());
        $this->assertFalse($offline->online());
    }

    public function testNullableFieldsAcceptNull(): void
    {
        $event = new Event(
            EventId::generate(),
            'no-optionals',
            'No Optionals',
            'general',
            '2025-09-01',
            null,
            null,
            false,
            null,
            null,
            [],
        );

        $this->assertNull($event->time());
        $this->assertNull($event->location());
        $this->assertNull($event->description());
        $this->assertNull($event->heroImage());
    }

    public function testGalleryIsReturnedAsArray(): void
    {
        $images = ['img1.jpg', 'img2.jpg'];
        $event  = $this->makeEvent(['gallery' => $images]);

        $this->assertSame($images, $event->gallery());
    }

    public function testEmptyGallery(): void
    {
        $event = $this->makeEvent(['gallery' => []]);

        $this->assertSame([], $event->gallery());
    }
}
