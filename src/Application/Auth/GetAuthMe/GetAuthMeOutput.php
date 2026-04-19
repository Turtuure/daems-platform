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
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $roleInTenant,
        public readonly ?string $tokenExpiresAt,
    ) {}

    /** @return array{user: array<string, mixed>, tenant: array<string, string>, role_in_tenant: ?string, token_expires_at: ?string} */
    public function toArray(): array
    {
        return [
            'user' => [
                'id'                => $this->userId,
                'name'              => $this->name,
                'email'             => $this->email,
                'is_platform_admin' => $this->isPlatformAdmin,
            ],
            'tenant' => [
                'slug' => $this->tenantSlug,
                'name' => $this->tenantName,
            ],
            'role_in_tenant'   => $this->roleInTenant,
            'token_expires_at' => $this->tokenExpiresAt,
        ];
    }
}
