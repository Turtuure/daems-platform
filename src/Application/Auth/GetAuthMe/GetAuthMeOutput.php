<?php

declare(strict_types=1);

namespace Daems\Application\Auth\GetAuthMe;

final class GetAuthMeOutput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isPlatformAdmin,
        public readonly bool $publicAvatarVisible,
        public readonly ?string $timeFormatOverride,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $tenantMemberNumberPrefix,
        public readonly string $tenantDefaultTimeFormat,
        public readonly string $effectiveTimeFormat,
        public readonly ?string $roleInTenant,
        public readonly ?string $tokenExpiresAt,
    ) {}

    /** @return array{user: array<string, mixed>, tenant: array<string, mixed>, role_in_tenant: ?string, token_expires_at: ?string, time_format: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'user' => [
                'id'                    => $this->userId,
                'name'                  => $this->name,
                'email'                 => $this->email,
                'is_platform_admin'     => $this->isPlatformAdmin,
                'public_avatar_visible' => $this->publicAvatarVisible,
                'time_format_override'  => $this->timeFormatOverride,
            ],
            'tenant' => [
                'slug'                 => $this->tenantSlug,
                'name'                 => $this->tenantName,
                'member_number_prefix' => $this->tenantMemberNumberPrefix,
                'default_time_format'  => $this->tenantDefaultTimeFormat,
            ],
            'role_in_tenant'   => $this->roleInTenant,
            'token_expires_at' => $this->tokenExpiresAt,
            // Convenience: pre-resolved effective value the frontend can read
            // without having to know the precedence rule.
            'time_format' => [
                'effective'         => $this->effectiveTimeFormat,
                'user_override'     => $this->timeFormatOverride,
                'tenant_default'    => $this->tenantDefaultTimeFormat,
            ],
        ];
    }
}
