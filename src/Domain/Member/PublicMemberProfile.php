<?php
declare(strict_types=1);

namespace Daems\Domain\Member;

final class PublicMemberProfile
{
    public function __construct(
        public readonly string $memberNumberRaw,
        public readonly string $name,
        public readonly string $memberType,
        public readonly ?string $role,
        public readonly ?string $joinedAt,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $tenantMemberNumberPrefix,
        public readonly bool $publicAvatarVisible,
        public readonly string $avatarInitials,
        public readonly ?string $avatarUrl,
    ) {}
}
