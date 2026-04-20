<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdmin;
use Daems\Application\Backstage\ListEventsForAdmin\ListEventsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

final class ListEventsForAdminTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';

    private function acting(bool $platformAdmin, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryEventRepository();
        (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(platformAdmin: false, role: null), null, null),
        );
    }

    public function test_returns_all_statuses_for_own_tenant(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('a', 'Draft one', 'upcoming', 'draft'));
        $repo->save($this->makeEvent('b', 'Pub one', 'upcoming', 'published'));
        $repo->save($this->makeEvent('c', 'Arch one', 'past', 'archived'));
        $repo->save($this->makeOtherTenantEvent('z', 'Other', 'upcoming', 'published'));

        $out = (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(true), null, null),
        );
        self::assertCount(3, $out->items);
        $titles = array_column($out->items, 'title');
        self::assertContains('Draft one', $titles);
        self::assertContains('Pub one', $titles);
        self::assertContains('Arch one', $titles);
        self::assertNotContains('Other', $titles);
    }

    public function test_filters_by_status_and_type(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('a', 'DA', 'upcoming', 'draft'));
        $repo->save($this->makeEvent('b', 'DB', 'past', 'draft'));
        $repo->save($this->makeEvent('c', 'PA', 'upcoming', 'published'));

        $out = (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(true), 'draft', 'upcoming'),
        );
        self::assertCount(1, $out->items);
        self::assertSame('DA', $out->items[0]['title']);
    }

    public function test_output_contains_registration_count(): void
    {
        $repo = new InMemoryEventRepository();
        $eventId = '01959900-0000-7000-8000-000000000001';
        $repo->save($this->makeEventWithId($eventId, 'E', 'upcoming', 'published'));
        $repo->register(new EventRegistration(
            'r1', $eventId, 'u1', '2026-04-20 12:00:00',
        ));

        $out = (new ListEventsForAdmin($repo))->execute(
            new ListEventsForAdminInput($this->acting(true), null, null),
        );
        self::assertSame(1, $out->items[0]['registration_count']);
    }

    private function makeEvent(string $idSuffix, string $title, string $type, string $status): Event
    {
        $hex = str_pad(dechex(ord($idSuffix[0])), 1, '0', STR_PAD_LEFT);
        return new Event(
            EventId::fromString('01959900-0000-7000-8000-0000000000' . str_pad($hex, 2, '0', STR_PAD_LEFT)),
            TenantId::fromString(self::TENANT),
            "slug-{$idSuffix}",
            $title, $type, '2026-06-01', null, 'HQ', false, 'Desc',
            null, [], $status,
        );
    }

    private function makeEventWithId(string $id, string $title, string $type, string $status): Event
    {
        return new Event(
            EventId::fromString($id),
            TenantId::fromString(self::TENANT),
            "slug-{$id}",
            $title, $type, '2026-06-01', null, 'HQ', false, 'Desc',
            null, [], $status,
        );
    }

    private function makeOtherTenantEvent(string $idSuffix, string $title, string $type, string $status): Event
    {
        $hex = str_pad(dechex(ord($idSuffix[0])), 1, '0', STR_PAD_LEFT);
        return new Event(
            EventId::fromString('01959900-0000-7000-8000-0000000001' . str_pad($hex, 2, '0', STR_PAD_LEFT)),
            TenantId::fromString(self::OTHER_TENANT),
            "slug-other-{$idSuffix}",
            $title, $type, '2026-06-01', null, 'HQ', false, 'Desc',
            null, [], $status,
        );
    }
}
