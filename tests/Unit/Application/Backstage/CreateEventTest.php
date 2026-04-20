<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\CreateEvent\CreateEvent;
use Daems\Application\Backstage\CreateEvent\CreateEventInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

final class CreateEventTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function acting(bool $platformAdmin = true, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function ids(string $fixed = '01959900-0000-7000-8000-000000000001'): IdGeneratorInterface
    {
        return new class($fixed) implements IdGeneratorInterface {
            private int $counter = 0;
            public function __construct(private readonly string $base) {}
            public function generate(): string
            {
                // Vary each call so slug collisions can be tested
                $this->counter++;
                if ($this->counter === 1) {
                    return $this->base;
                }
                // Return different IDs for subsequent calls
                $suffix = str_pad((string) $this->counter, 2, '0', STR_PAD_LEFT);
                return substr($this->base, 0, -2) . $suffix;
            }
        };
    }

    private function validInput(
        bool $publishImmediately = false,
        ?ActingUser $acting = null,
    ): CreateEventInput {
        return new CreateEventInput(
            acting: $acting ?? $this->acting(),
            title: 'Annual Tech Conference',
            type: 'upcoming',
            eventDate: '2026-09-15',
            eventTime: '10:00',
            location: 'Helsinki',
            isOnline: false,
            description: 'A great event for developers and tech enthusiasts.',
            publishImmediately: $publishImmediately,
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            $this->validInput(acting: $this->acting(false, null)),
        );
    }

    public function test_rejects_short_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), 'Hi', 'upcoming', '2026-09-15', null, 'HQ', false, 'A description that is long enough for validation purposes.'),
        );
    }

    public function test_rejects_too_long_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), str_repeat('x', 201), 'upcoming', '2026-09-15', null, 'HQ', false, 'A description that is long enough for validation purposes.'),
        );
    }

    public function test_rejects_invalid_type(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), 'Valid Title', 'conference', '2026-09-15', null, 'HQ', false, 'A description that is long enough for validation purposes.'),
        );
    }

    public function test_rejects_invalid_date(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), 'Valid Title', 'upcoming', '15-09-2026', null, 'HQ', false, 'A description that is long enough for validation purposes.'),
        );
    }

    public function test_rejects_short_description(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), 'Valid Title', 'upcoming', '2026-09-15', null, 'HQ', false, 'Too short'),
        );
    }

    public function test_rejects_missing_location_when_not_online(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryEventRepository();
        (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), 'Valid Title', 'upcoming', '2026-09-15', null, null, false, 'A description that is long enough for validation purposes.'),
        );
    }

    public function test_slug_auto_generated_from_title(): void
    {
        $repo = new InMemoryEventRepository();
        $out = (new CreateEvent($repo, $this->ids()))->execute($this->validInput());
        self::assertSame('annual-tech-conference', $out->slug);
    }

    public function test_slug_collision_appends_suffix(): void
    {
        $repo = new InMemoryEventRepository();
        $uc = new CreateEvent($repo, $this->ids());
        // Create first event
        $uc->execute($this->validInput());
        // Create second event with same title
        $out2 = $uc->execute($this->validInput());
        self::assertStringStartsWith('annual-tech-conference-', $out2->slug);
    }

    public function test_default_status_is_draft(): void
    {
        $repo = new InMemoryEventRepository();
        $out = (new CreateEvent($repo, $this->ids()))->execute($this->validInput());
        $event = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($event);
        self::assertSame('draft', $event->status());
    }

    public function test_status_published_when_publish_immediately_true(): void
    {
        $repo = new InMemoryEventRepository();
        $out = (new CreateEvent($repo, $this->ids()))->execute($this->validInput(publishImmediately: true));
        $event = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($event);
        self::assertSame('published', $event->status());
    }

    public function test_online_event_does_not_require_location(): void
    {
        $repo = new InMemoryEventRepository();
        $out = (new CreateEvent($repo, $this->ids()))->execute(
            new CreateEventInput($this->acting(), 'Online Event Title', 'online', '2026-09-15', null, null, true, 'A great event for developers and tech enthusiasts worldwide.'),
        );
        self::assertNotEmpty($out->id);
    }
}
