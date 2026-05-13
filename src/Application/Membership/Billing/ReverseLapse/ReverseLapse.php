<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReverseLapse;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideId;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\User\UserRepositoryInterface;

/**
 * GSA-only override that flips a LAPSED user back to ACTIVE. Records two
 * audit trails:
 *   - member_status_audit (lapsed → active, performed_by=actor)
 *   - gsa_overrides (action=reverse_lapse, targetId=userId, reason=justification)
 *
 * The justification length is double-validated: this use case rejects empty,
 * and GsaOverride's constructor enforces a 10-char minimum (DomainException).
 */
final class ReverseLapse
{
    public function __construct(
        private readonly UserRepositoryInterface              $users,
        private readonly MemberStatusAuditRepositoryInterface $statusAudit,
        private readonly GsaOverrideRepositoryInterface       $gsaOverrides,
        private readonly IdGeneratorInterface                 $ids,
        private readonly Clock                                $clock,
    ) {}

    public function handle(ReverseLapseInput $in): void
    {
        if (!$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only GSA can reverse lapse decisions');
        }
        if (trim($in->justification) === '') {
            throw new \InvalidArgumentException('Justification required for GSA override');
        }

        $user = $this->users->findById($in->userId->value());
        if ($user === null) {
            throw new \DomainException("User not found: {$in->userId->value()}");
        }
        if ($user->membershipStatus() !== 'lapsed') {
            throw new \DomainException("User is not LAPSED; cannot reverse (current: {$user->membershipStatus()})");
        }

        $now = $this->clock->now();
        $this->users->updateMembershipStatus($in->userId->value(), 'active');

        $this->statusAudit->save(new MemberStatusAudit(
            id:                  $this->ids->generate(),
            tenantId:            $in->tenantId->value(),
            userId:              $in->userId->value(),
            previousStatus:      'lapsed',
            newStatus:           'active',
            reason:              'GSA override: lapse reversed — ' . $in->justification,
            performedByAdminId:  $in->actor->id->value(),
            createdAt:           $now,
        ));

        $this->gsaOverrides->save(new GsaOverride(
            id:          GsaOverrideId::generate(),
            gsaUserId:   $in->actor->id,
            tenantId:    $in->tenantId,
            action:      GsaOverrideAction::ReverseLapse,
            targetId:    $in->userId->value(),
            reason:      $in->justification,
            performedAt: $now,
        ));
    }
}
