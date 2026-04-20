<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\UpdateEvent\UpdateEvent;
use Daems\Application\Backstage\UpdateEvent\UpdateEventInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

final class UpdateEventTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';
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

    private function makeEvent(string $id = self::EVENT_ID, string $tenantId = self::TENANT): Event
    {
        return new Event(
            EventId::fromString($id),
            TenantId::fromString($tenantId),
            'original-slug',
            'Original Title', 'upcoming', '2026-06-01',
            '10:00', 'Berlin', false,
            'This is the original description text.',
            null, [], 'draft',
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(null), self::EVENT_ID, 'New Title', null, null, null, null, null, null, null, null),
        );
    }

    public function test_not_found_event(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryEventRepository();
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), '01959900-0000-7000-8000-000000000099', 'New Title', null, null, null, null, null, null, null, null),
        );
    }

    public function test_cross_tenant_returns_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryEventRepository();
        // Save event for other tenant
        $repo->save($this->makeEvent(self::EVENT_ID, self::OTHER_TENANT));
        // Acting user is in TENANT, not OTHER_TENANT
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), self::EVENT_ID, 'New Title', null, null, null, null, null, null, null, null),
        );
    }

    public function test_partial_update_only_changes_title(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), self::EVENT_ID, 'Updated Title', null, null, null, null, null, null, null, null),
        );
        $updated = $repo->findByIdForTenant(self::EVENT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($updated);
        self::assertSame('Updated Title', $updated->title());
        self::assertSame('upcoming', $updated->type()); // unchanged
        self::assertSame('2026-06-01', $updated->date()); // unchanged
        self::assertSame('This is the original description text.', $updated->description()); // unchanged
    }

    public function test_validation_error_bad_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), self::EVENT_ID, 'Hi', null, null, null, null, null, null, null, null),
        );
    }

    public function test_validation_error_bad_description(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), self::EVENT_ID, null, null, null, null, null, null, 'Short', null, null),
        );
    }

    public function test_validation_error_bad_date(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), self::EVENT_ID, null, null, '20260601', null, null, null, null, null, null),
        );
    }

    public function test_accepts_hero_image_and_gallery(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput(
                $this->acting(), self::EVENT_ID,
                null, null, null, null, null, null, null,
                '/uploads/events/abc/hero.webp',
                ['/uploads/events/abc/1.webp', '/uploads/events/abc/2.webp'],
            ),
        );
        $updated = $repo->findByIdForTenant(self::EVENT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($updated);
        self::assertSame('/uploads/events/abc/hero.webp', $updated->heroImage());
        self::assertCount(2, $updated->gallery());
    }

    public function test_does_not_change_status(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent()); // status=draft
        (new UpdateEvent($repo))->execute(
            new UpdateEventInput($this->acting(), self::EVENT_ID, 'Updated Title Yes', null, null, null, null, null, null, null, null),
        );
        $updated = $repo->findByIdForTenant(self::EVENT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($updated);
        self::assertSame('draft', $updated->status()); // still draft
    }
}
