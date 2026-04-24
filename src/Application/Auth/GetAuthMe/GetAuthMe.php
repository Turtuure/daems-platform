<?php

declare(strict_types=1);

namespace Daems\Application\Auth\GetAuthMe;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use RuntimeException;

final class GetAuthMe
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly TenantRepositoryInterface $tenants,
        private readonly AuthTokenRepositoryInterface $tokens,
    ) {}

    public function execute(ActingUser $actor, string $bearerToken): GetAuthMeOutput
    {
        $user = $this->users->findById($actor->id->value())
            ?? throw new RuntimeException('ActingUser points to missing user: ' . $actor->id->value());

        $tenant = $this->tenants->findById($actor->activeTenant)
            ?? throw new RuntimeException('ActingUser points to missing tenant: ' . $actor->activeTenant->value());

        $hash = hash('sha256', $bearerToken);
        $token = $this->tokens->findByHash($hash);
        $expiresAt = $token !== null ? $token->expiresAt()->format(\DATE_ATOM) : null;

        return new GetAuthMeOutput(
            userId:                   $actor->id->value(),
            name:                     $user->name(),
            email:                    $actor->email,
            isPlatformAdmin:          $actor->isPlatformAdmin,
            publicAvatarVisible:      $user->publicAvatarVisible(),
            tenantSlug:               $tenant->slug->value(),
            tenantName:               $tenant->name,
            tenantMemberNumberPrefix: $tenant->memberNumberPrefix,
            roleInTenant:             $actor->roleInActiveTenant?->value,
            tokenExpiresAt:           $expiresAt,
        );
    }
}
