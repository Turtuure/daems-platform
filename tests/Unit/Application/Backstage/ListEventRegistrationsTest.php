<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrations;
use Daems\Application\Backstage\ListEventRegistrations\ListEventRegistrationsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

final class ListEventRegistrationsTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const EVENT_ID = '01959900-0000-7000-8000-000000000001';

    private function acting(?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 'admin@x',
            isPlatformAdmin: false,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function makeEvent(): Event
    {
        return new Event(
            EventId::fromString(self::EVENT_ID),
            TenantId::fromString(self::TENANT),
            'my-event', 'My Event', 'upcoming', '2026-06-01',
            null, 'HQ', false, 'A great event description here.',
            null, [], 'published',
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new ListEventRegistrations($repo))->execute(
            new ListEventRegistrationsInput($this->acting(null), self::EVENT_ID),
        );
    }

    public function test_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryEventRepository();
        (new ListEventRegistrations($repo))->execute(
            new ListEventRegistrationsInput($this->acting(), '01959900-0000-7000-8000-000000000099'),
        );
    }

    public function test_returns_registrations(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        $repo->seedAdminRegistration(self::EVENT_ID, 'u1', 'Alice', 'alice@x.com', '2026-04-20 12:00:00');
        $repo->seedAdminRegistration(self::EVENT_ID, 'u2', 'Bob', 'bob@x.com', '2026-04-21 09:00:00');

        $out = (new ListEventRegistrations($repo))->execute(
            new ListEventRegistrationsInput($this->acting(), self::EVENT_ID),
        );
        self::assertCount(2, $out->items);
        self::assertSame('alice@x.com', $out->items[0]['email']);
    }
}
