<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Event;

use Daems\Application\Event\RegisterForEvent\RegisterForEvent;
use Daems\Application\Event\RegisterForEvent\RegisterForEventInput;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class RegisterForEventTest extends TestCase
{
    private function makeEvent(): Event
    {
        return new Event(
            EventId::generate(),
            'annual-meeting-2025',
            'Annual Meeting 2025',
            'general',
            '2025-09-01',
            '18:00',
            'Helsinki',
            false,
            null,
            null,
            [],
        );
    }

    public function testReturnsParticipantCountOnSuccessfulRegistration(): void
    {
        $event = $this->makeEvent();

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($event);
        $repo->method('isRegistered')->willReturn(false);
        $repo->method('countRegistrations')->willReturn(1);
        $repo->expects($this->once())->method('register');

        $out = (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput('annual-meeting-2025', 'user-uuid-0001'),
        );

        $this->assertNull($out->error);
        $this->assertSame(1, $out->participantCount);
    }

    public function testReturnsErrorWhenEventNotFound(): void
    {
        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);
        $repo->expects($this->never())->method('register');

        $out = (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput('nonexistent-event', 'user-uuid-0001'),
        );

        $this->assertNotNull($out->error);
        $this->assertSame(0, $out->participantCount);
    }

    public function testReturnsErrorWhenAlreadyRegistered(): void
    {
        $event = $this->makeEvent();

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($event);
        $repo->method('isRegistered')->willReturn(true);
        $repo->method('countRegistrations')->willReturn(5);
        $repo->expects($this->never())->method('register');

        $out = (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput('annual-meeting-2025', 'user-uuid-0001'),
        );

        $this->assertNotNull($out->error);
        $this->assertSame(5, $out->participantCount);
    }

    public function testRegistrationCallsRepositoryRegisterWithCorrectEventId(): void
    {
        $event = $this->makeEvent();
        $eventId = $event->id()->value();

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($event);
        $repo->method('isRegistered')->willReturn(false);
        $repo->method('countRegistrations')->willReturn(2);

        $repo->expects($this->once())->method('register')->with(
            $this->callback(fn($reg) => $reg->eventId() === $eventId),
        );

        (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput('annual-meeting-2025', 'user-uuid-0001'),
        );
    }
}
