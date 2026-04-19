<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Event;

use Daems\Application\Event\RegisterForEvent\RegisterForEvent;
use Daems\Application\Event\RegisterForEvent\RegisterForEventInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class RegisterForEventTest extends TestCase
{
    private function makeEvent(): Event
    {
        return new Event(
            EventId::generate(),
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
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

    private function acting(): ActingUser
    {
        // TEMP: PR 2 Task 17/18 will supply real tenant context.
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'test@daems.fi',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            roleInActiveTenant: UserTenantRole::Registered,
        );
    }

    public function testReturnsParticipantCountOnSuccessfulRegistration(): void
    {
        $event = $this->makeEvent();

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlugForTenant')->willReturn($event);
        $repo->method('isRegistered')->willReturn(false);
        $repo->method('countRegistrations')->willReturn(1);
        $repo->expects($this->once())->method('register');

        $out = (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput($this->acting(), 'annual-meeting-2025'),
        );

        $this->assertNull($out->error);
        $this->assertSame(1, $out->participantCount);
    }

    public function testReturnsErrorWhenEventNotFound(): void
    {
        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlugForTenant')->willReturn(null);
        $repo->expects($this->never())->method('register');

        $out = (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput($this->acting(), 'nonexistent-event'),
        );

        $this->assertNotNull($out->error);
        $this->assertSame(0, $out->participantCount);
    }

    public function testReturnsErrorWhenAlreadyRegistered(): void
    {
        $event = $this->makeEvent();

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlugForTenant')->willReturn($event);
        $repo->method('isRegistered')->willReturn(true);
        $repo->method('countRegistrations')->willReturn(5);
        $repo->expects($this->never())->method('register');

        $out = (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput($this->acting(), 'annual-meeting-2025'),
        );

        $this->assertNotNull($out->error);
        $this->assertSame(5, $out->participantCount);
    }

    public function testRegistrationCallsRepositoryRegisterWithCorrectEventId(): void
    {
        $event = $this->makeEvent();
        $eventId = $event->id()->value();

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlugForTenant')->willReturn($event);
        $repo->method('isRegistered')->willReturn(false);
        $repo->method('countRegistrations')->willReturn(2);

        $repo->expects($this->once())->method('register')->with(
            $this->callback(fn($reg) => $reg->eventId() === $eventId),
        );

        (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput($this->acting(), 'annual-meeting-2025'),
        );
    }

    public function testRegistrationUsesActingUserId(): void
    {
        $event = $this->makeEvent();
        // TEMP: PR 2 Task 17/18 will supply real tenant context.
        $acting = new ActingUser(
            id:                 UserId::generate(),
            email:              'test@daems.fi',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            roleInActiveTenant: UserTenantRole::Registered,
        );

        $repo = $this->createMock(EventRepositoryInterface::class);
        $repo->method('findBySlugForTenant')->willReturn($event);
        $repo->method('isRegistered')->willReturn(false);
        $repo->method('countRegistrations')->willReturn(1);

        $capturedUserId = null;
        $repo->expects($this->once())->method('register')->with(
            $this->callback(function ($reg) use (&$capturedUserId): bool {
                $capturedUserId = $reg->userId();
                return true;
            }),
        );

        (new RegisterForEvent($repo))->execute(
            new RegisterForEventInput($acting, 'annual-meeting-2025'),
        );

        $this->assertSame($acting->id->value(), $capturedUserId);
    }
}
