<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DeleteEventImage\DeleteEventImage;
use Daems\Application\Backstage\DeleteEventImage\DeleteEventImageInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use Daems\Tests\Support\Fake\InMemoryImageStorage;
use PHPUnit\Framework\TestCase;

final class DeleteEventImageTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const EVENT_ID = '01959900-0000-7000-8000-000000000001';
    private const HERO_URL = '/uploads/events/abc/hero.webp';
    private const GALLERY_URL_1 = '/uploads/events/abc/1.webp';
    private const GALLERY_URL_2 = '/uploads/events/abc/2.webp';

    private function acting(?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 'admin@x',
            isPlatformAdmin: false,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function makeEvent(
        ?string $heroImage = null,
        array $gallery = [],
    ): Event {
        return new Event(
            EventId::fromString(self::EVENT_ID),
            TenantId::fromString(self::TENANT),
            'my-event', 'My Event', 'upcoming', '2026-06-01',
            null, 'HQ', false, 'A great event description here.',
            $heroImage, $gallery, 'published',
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent(self::HERO_URL));
        (new DeleteEventImage($repo, new InMemoryImageStorage()))->execute(
            new DeleteEventImageInput($this->acting(null), self::EVENT_ID, self::HERO_URL),
        );
    }

    public function test_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryEventRepository();
        (new DeleteEventImage($repo, new InMemoryImageStorage()))->execute(
            new DeleteEventImageInput($this->acting(), '01959900-0000-7000-8000-000000000099', self::HERO_URL),
        );
    }

    public function test_deletes_hero_image(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent(self::HERO_URL));
        $storage = new InMemoryImageStorage();
        $storage->stored[self::HERO_URL] = 'image/webp';

        (new DeleteEventImage($repo, $storage))->execute(
            new DeleteEventImageInput($this->acting(), self::EVENT_ID, self::HERO_URL),
        );

        // Storage should have deleted the URL
        self::assertArrayNotHasKey(self::HERO_URL, $storage->stored);
        // Event's hero_image should be null
        $event = $repo->findByIdForTenant(self::EVENT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($event);
        self::assertNull($event->heroImage());
    }

    public function test_deletes_from_gallery(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent(null, [self::GALLERY_URL_1, self::GALLERY_URL_2]));
        $storage = new InMemoryImageStorage();
        $storage->stored[self::GALLERY_URL_1] = 'image/webp';

        (new DeleteEventImage($repo, $storage))->execute(
            new DeleteEventImageInput($this->acting(), self::EVENT_ID, self::GALLERY_URL_1),
        );

        self::assertArrayNotHasKey(self::GALLERY_URL_1, $storage->stored);
        $event = $repo->findByIdForTenant(self::EVENT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($event);
        self::assertCount(1, $event->gallery());
        self::assertSame(self::GALLERY_URL_2, $event->gallery()[0]);
    }

    public function test_no_op_when_url_does_not_match(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent(self::HERO_URL, [self::GALLERY_URL_1]));
        $storage = new InMemoryImageStorage();

        (new DeleteEventImage($repo, $storage))->execute(
            new DeleteEventImageInput($this->acting(), self::EVENT_ID, '/uploads/events/abc/nonexistent.webp'),
        );

        // No side effects
        $event = $repo->findByIdForTenant(self::EVENT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($event);
        self::assertSame(self::HERO_URL, $event->heroImage());
        self::assertCount(1, $event->gallery());
        self::assertEmpty($storage->stored);
    }
}
