<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

final class Event
{
    public function __construct(
        private readonly EventId $id,
        private readonly TenantId $tenantId,
        private readonly string $slug,
        private readonly string $title,
        private readonly string $type,
        private readonly string $date,
        private readonly ?string $time,
        private readonly ?string $location,
        private readonly bool $online,
        private readonly ?string $description,
        private readonly ?string $heroImage,
        private readonly array $gallery,
        private readonly string $status = 'published',
    ) {}

    public function id(): EventId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function type(): string { return $this->type; }
    public function date(): string { return $this->date; }
    public function time(): ?string { return $this->time; }
    public function location(): ?string { return $this->location; }
    public function online(): bool { return $this->online; }
    public function description(): ?string { return $this->description; }
    public function heroImage(): ?string { return $this->heroImage; }
    public function gallery(): array { return $this->gallery; }
    public function status(): string { return $this->status; }
}
