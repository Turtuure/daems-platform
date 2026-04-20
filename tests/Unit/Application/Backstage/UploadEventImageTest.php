<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\UploadEventImage\UploadEventImage;
use Daems\Application\Backstage\UploadEventImage\UploadEventImageInput;
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
use Daems\Tests\Support\Fake\InMemoryImageStorage;
use PHPUnit\Framework\TestCase;

final class UploadEventImageTest extends TestCase
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
        $repo->save($this->makeEvent());
        (new UploadEventImage($repo, new InMemoryImageStorage()))->execute(
            new UploadEventImageInput($this->acting(null), self::EVENT_ID, '/tmp/file.jpg', 'image/jpeg'),
        );
    }

    public function test_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryEventRepository();
        (new UploadEventImage($repo, new InMemoryImageStorage()))->execute(
            new UploadEventImageInput($this->acting(), '01959900-0000-7000-8000-000000000099', '/tmp/file.jpg', 'image/jpeg'),
        );
    }

    public function test_15_image_cap_with_hero_and_gallery(): void
    {
        $this->expectException(ValidationException::class);
        $gallery = array_map(
            fn(int $i): string => "/uploads/events/abc/{$i}.webp",
            range(1, 14),
        );
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('/uploads/events/abc/hero.webp', $gallery));
        // hero + 14 gallery = 15 images → cap reached
        (new UploadEventImage($repo, new InMemoryImageStorage()))->execute(
            new UploadEventImageInput($this->acting(), self::EVENT_ID, '/tmp/file.jpg', 'image/jpeg'),
        );
    }

    public function test_14_images_allows_upload(): void
    {
        $gallery = array_map(
            fn(int $i): string => "/uploads/events/abc/{$i}.webp",
            range(1, 13),
        );
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent('/uploads/events/abc/hero.webp', $gallery));
        // hero + 13 gallery = 14 images → cap NOT reached
        $storage = new InMemoryImageStorage();
        $out = (new UploadEventImage($repo, $storage))->execute(
            new UploadEventImageInput($this->acting(), self::EVENT_ID, '/tmp/file.jpg', 'image/jpeg'),
        );
        self::assertNotEmpty($out->url);
        self::assertCount(1, $storage->stored);
    }

    public function test_delegates_to_image_storage(): void
    {
        $repo = new InMemoryEventRepository();
        $repo->save($this->makeEvent());
        $storage = new InMemoryImageStorage();
        $out = (new UploadEventImage($repo, $storage))->execute(
            new UploadEventImageInput($this->acting(), self::EVENT_ID, '/tmp/file.jpg', 'image/jpeg'),
        );
        self::assertCount(1, $storage->stored);
        self::assertSame($out->url, array_key_first($storage->stored));
    }
}
