<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

use Daems\Domain\Tenant\TenantId;

final class MemberApplication
{
    public function __construct(
        private readonly MemberApplicationId $id,
        private readonly TenantId $tenantId,
        private readonly string $name,
        private readonly string $email,
        private readonly string $dateOfBirth,
        private readonly ?string $country,
        private readonly string $motivation,
        private readonly ?string $howHeard,
        private readonly string $status,
    ) {}

    public function id(): MemberApplicationId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function name(): string { return $this->name; }
    public function email(): string { return $this->email; }
    public function dateOfBirth(): string { return $this->dateOfBirth; }
    public function country(): ?string { return $this->country; }
    public function motivation(): string { return $this->motivation; }
    public function howHeard(): ?string { return $this->howHeard; }
    public function status(): string { return $this->status; }
}
