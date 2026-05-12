<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\LapseInactiveMember;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\User\UserRepositoryInterface;

/**
 * Flips a member's status from 'active' to 'lapsed' per § 4 (two consecutive
 * years of unpaid fees). Idempotent: already-non-active members are a no-op.
 *
 * Driven by LapseInactiveMembersCommand (Wave F F4) which discovers candidates
 * via MemberFeeInvoiceRepository::findUsersWithConsecutiveOverdueYears().
 *
 * The audit row records `performed_by = null` to denote a cron-driven change
 * (vs. an admin-initiated expulsion).
 */
final class LapseInactiveMember
{
    public function __construct(
        private readonly UserRepositoryInterface              $users,
        private readonly MemberStatusAuditRepositoryInterface $audit,
        private readonly IdGeneratorInterface                 $ids,
        private readonly Clock                                $clock,
    ) {}

    public function handle(LapseInactiveMemberInput $in): LapseInactiveMemberOutput
    {
        $user = $this->users->findById($in->userId->value());
        if ($user === null) {
            throw new \DomainException("User not found: {$in->userId->value()}");
        }

        $previousStatus = $user->membershipStatus();
        if ($previousStatus !== 'active') {
            return new LapseInactiveMemberOutput(
                lapsed:         false,
                previousStatus: $previousStatus,
                newStatus:      $previousStatus,
            );
        }

        $this->users->updateMembershipStatus($in->userId->value(), 'lapsed');

        $years = implode(', ', $in->overdueYears);
        $reason = "2v maksamatta: {$years}";
        $this->audit->save(new MemberStatusAudit(
            id:                  $this->ids->generate(),
            tenantId:            $in->tenantId->value(),
            userId:              $in->userId->value(),
            previousStatus:      $previousStatus,
            newStatus:           'lapsed',
            reason:              $reason,
            performedByAdminId:  null,
            createdAt:           $this->clock->now(),
        ));

        return new LapseInactiveMemberOutput(
            lapsed:         true,
            previousStatus: $previousStatus,
            newStatus:      'lapsed',
        );
    }
}
