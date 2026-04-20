<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ActivateSupporter;

use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class SupporterActivationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    /**
     * @param array{name: string, email: string, country: ?string} $applicationFields
     * @return array{userId: string}
     */
    public function execute(string $tenantId, array $applicationFields): array
    {
        $now    = $this->clock->now();
        $userId = $this->ids->generate();

        $this->users->createActivated($userId, [
            'name'              => $applicationFields['name'],
            'email'             => $applicationFields['email'],
            'date_of_birth'     => null,
            'country'           => $applicationFields['country'] ?? '',
            'membership_type'   => 'supporter',
            'membership_status' => 'active',
            'member_number'     => null,
        ], $now);

        $this->userTenants->attach(
            UserId::fromString($userId),
            TenantId::fromString($tenantId),
            UserTenantRole::Supporter,
        );

        return ['userId' => $userId];
    }
}
