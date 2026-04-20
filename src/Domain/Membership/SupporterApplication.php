<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

final class SupporterApplication
{
    public function __construct(
        private readonly SupporterApplicationId $id,
        private readonly TenantId $tenantId,
        private readonly string $orgName,
        private readonly string $contactPerson,
        private readonly ?string $regNo,
        private readonly string $email,
        private readonly ?string $country,
        private readonly string $motivation,
        private readonly ?string $howHeard,
        private readonly string $status,
        private readonly ?string $createdAt = null,
    ) {}

    public function id(): SupporterApplicationId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function orgName(): string { return $this->orgName; }
    public function contactPerson(): string { return $this->contactPerson; }
    public function regNo(): ?string { return $this->regNo; }
    public function email(): string { return $this->email; }
    public function country(): ?string { return $this->country; }
    public function motivation(): string { return $this->motivation; }
    public function howHeard(): ?string { return $this->howHeard; }
    public function status(): string { return $this->status; }
    public function createdAt(): ?string { return $this->createdAt; }
}
