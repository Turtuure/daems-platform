<?php

declare(strict_types=1);

namespace Daems\Domain\User;

final class User
{
    public function __construct(
        private readonly UserId $id,
        private readonly string $name,
        private readonly string $email,
        private readonly ?string $passwordHash,
        private readonly ?string $dateOfBirth,
        private readonly string $country = '',
        private readonly string $addressStreet = '',
        private readonly string $addressZip = '',
        private readonly string $addressCity = '',
        private readonly string $addressCountry = '',
        private readonly string $membershipType = 'individual',
        private readonly string $membershipStatus = 'active',
        private readonly ?string $memberNumber = null,
        private readonly string $createdAt = '',
        private readonly bool $isPlatformAdmin = false,
        private readonly ?\DateTimeImmutable $deletedAt = null,
    ) {}

    public function id(): UserId { return $this->id; }
    public function name(): string { return $this->name; }
    public function email(): string { return $this->email; }
    public function passwordHash(): ?string { return $this->passwordHash; }
    public function dateOfBirth(): ?string { return $this->dateOfBirth; }
    public function country(): string { return $this->country; }
    public function addressStreet(): string { return $this->addressStreet; }
    public function addressZip(): string { return $this->addressZip; }
    public function addressCity(): string { return $this->addressCity; }
    public function addressCountry(): string { return $this->addressCountry; }
    public function membershipType(): string { return $this->membershipType; }
    public function membershipStatus(): string { return $this->membershipStatus; }
    public function memberNumber(): ?string { return $this->memberNumber; }
    public function createdAt(): string { return $this->createdAt; }
    public function isPlatformAdmin(): bool { return $this->isPlatformAdmin; }
    public function deletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
}
