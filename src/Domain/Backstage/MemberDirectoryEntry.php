<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

final class MemberDirectoryEntry
{
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $membershipType,
        public readonly string $membershipStatus,
        public readonly ?string $memberNumber,
        public readonly ?string $roleInTenant,
        public readonly string $joinedAt,        // ISO-8601 (membership start)
        public readonly ?string $country,
        public readonly ?string $dateOfBirth,
        public readonly string $createdAt,       // user account created_at
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id'                    => $this->userId,
            'name'                  => $this->name,
            'email'                 => $this->email,
            'membership_type'       => $this->membershipType,
            'membership_status'     => $this->membershipStatus,
            'member_number'         => $this->memberNumber,
            'role_in_tenant'        => $this->roleInTenant,
            'country'               => $this->country,
            'date_of_birth'         => $this->dateOfBirth,
            'created_at'            => $this->createdAt,
            'membership_started_at' => $this->joinedAt,
        ];
    }
}
