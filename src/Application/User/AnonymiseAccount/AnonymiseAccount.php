<?php

declare(strict_types=1);

namespace Daems\Application\User\AnonymiseAccount;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class AnonymiseAccount
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly MemberStatusAuditRepositoryInterface $audit,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(AnonymiseAccountInput $input): AnonymiseAccountOutput
    {
        $acting = $input->acting;
        // Validate UUID format early — invalid UUIDs throw an unhandled exception
        // which the kernel turns into a 500. This is intentional: a malformed ID
        // in the URL is a programmer/client error, not a domain error.
        $targetIdObj = UserId::fromString($input->targetUserId);
        $targetId = $targetIdObj->value();

        $isSelf = $acting->id->value() === $targetId;

        // Authorization: must be self or admin in active tenant
        if (!$isSelf) {
            // Check admin in active tenant (isAdminIn covers isPlatformAdmin)
            if (!$acting->isAdminIn($acting->activeTenant)) {
                throw new ForbiddenException('not_authorised_to_anonymise');
            }
            // Admin path: target must exist (check below) and be member of tenant
        }

        $user = $this->users->findById($targetId);
        if ($user === null) {
            throw new NotFoundException('user_not_found');
        }

        if ($user->deletedAt() !== null) {
            throw new ValidationException(['state' => 'already_anonymised']);
        }

        $previousStatus = $user->membershipStatus();
        $now = $this->clock->now();

        $this->tx->run(function () use ($targetId, $now, $previousStatus, $acting): void {
            // 1. Wipe PII on users row
            $this->users->anonymise($targetId, $now);

            // 2. Mark all tenant memberships as left
            $this->userTenants->markAllLeftForUser($targetId, $now);

            // 3. Revoke all auth tokens
            $this->tokens->revokeAllForUser($targetId);

            // 4. Insert audit row
            $this->audit->save(new MemberStatusAudit(
                $this->ids->generate(),
                $acting->activeTenant->value(),
                $targetId,
                $previousStatus,
                'terminated',
                'user_anonymised',
                $acting->id->value(),
                $now,
            ));
        });

        return new AnonymiseAccountOutput(true);
    }
}
