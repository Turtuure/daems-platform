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
        public readonly string $joinedAt,        // ISO-8601
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'userId'           => $this->userId,
            'name'             => $this->name,
            'email'            => $this->email,
            'membershipType'   => $this->membershipType,
            'membershipStatus' => $this->membershipStatus,
            'memberNumber'     => $this->memberNumber,
            'roleInTenant'     => $this->roleInTenant,
            'joinedAt'         => $this->joinedAt,
        ];
    }
}
