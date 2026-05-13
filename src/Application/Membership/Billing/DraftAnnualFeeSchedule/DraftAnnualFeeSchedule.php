<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\DraftAnnualFeeSchedule;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionMode;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionThreshold;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardDecisionVoteVisibility;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use DomainException;
use InvalidArgumentException;

/**
 * Routes based on tenant_governance_settings.requires_formal_decision_for_fees:
 *   false → create rows status=Active directly, supersede priors, return decision_id=null
 *   true  → create rows status=Proposed + a BoardDecision (decision_type='annual_fee_schedule'),
 *           return its id. AnnualFeeScheduleExecutor flips Proposed→Active on
 *           decision pass via reverse-FK lookup (no payload on BoardDecision itself).
 */
final class DraftAnnualFeeSchedule
{
    public function __construct(
        private readonly AnnualFeeScheduleRepositoryInterface         $schedules,
        private readonly BoardDecisionRepositoryInterface             $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface  $settings,
        private readonly BoardRepositoryInterface                     $boards,
        private readonly TenantRepositoryInterface                    $tenants,
        private readonly Clock                                        $clock,
    ) {}

    public function handle(DraftAnnualFeeScheduleInput $in): DraftAnnualFeeScheduleOutput
    {
        if (!$in->actor->isAdminIn($in->tenantId)) {
            throw new ForbiddenException("Only tenant admins or GSA can draft annual fee schedules");
        }

        $expectedTypes = ['BASIC', 'FULL', 'SUPPORTING'];
        $providedTypes = array_keys($in->fees);
        sort($providedTypes);
        if ($expectedTypes !== $providedTypes) {
            throw new InvalidArgumentException(
                "Must provide exactly 3 fees (SUPPORTING/BASIC/FULL); got " . implode(',', array_keys($in->fees))
            );
        }

        $settings = $this->settings->find($in->tenantId);
        $requiresFormal = $settings !== null && $settings->requiresFormalDecisionForFees();
        $expirationDays = $settings !== null ? $settings->decisionExpirationDays : 60;
        $now = $this->clock->now();

        $decisionId = null;
        $status = AnnualFeeScheduleStatus::Active;

        if ($requiresFormal) {
            $board = $this->boards->findForTenant($in->tenantId);
            if ($board === null) {
                throw new DomainException(
                    "Cannot create formal annual_fee_schedule decision: tenant has no board"
                );
            }

            $decision = new BoardDecision(
                id:               BoardDecisionId::generate(),
                boardId:          $board->id,
                decisionType:     BoardDecisionType::AnnualFeeSchedule,
                threshold:        BoardDecisionThreshold::Majority,
                mode:             BoardDecisionMode::Sync,
                voteVisibility:   BoardDecisionVoteVisibility::Visible,
                status:           BoardDecisionStatus::Pending,
                proposedByUserId: $in->actor->id,
                proposedAt:       $now,
                expiresAt:        $now->modify("+{$expirationDays} days"),
                resolvedAt:       null,
                meetingReference: null,
                withdrawalReason: null,
                viaDelegation:    false,
                delegationId:     null,
            );
            $this->decisions->save($decision);
            $decisionId = $decision->id->value();
            $status = AnnualFeeScheduleStatus::Proposed;
        } else {
            // Direct activation: supersede any prior active rows for these (year, fee_type).
            foreach (['SUPPORTING', 'BASIC', 'FULL'] as $typeValue) {
                $prev = $this->schedules->findActiveFor($in->tenantId, $in->year, MembershipType::from($typeValue));
                if ($prev !== null) {
                    $prev->supersede($now);
                    $this->schedules->save($prev);
                }
            }
        }

        $tenant = $this->tenants->findById($in->tenantId);
        $currency = $tenant?->currency() ?? 'EUR';

        $scheduleIds = [];
        foreach ($in->fees as $typeValue => $amountCents) {
            $schedule = new AnnualFeeSchedule(
                id:           AnnualFeeScheduleId::generate(),
                tenantId:     $in->tenantId,
                year:         $in->year,
                feeType:      MembershipType::from($typeValue),
                amountCents:  $amountCents,
                currency:     $currency,
                status:       $status,
                decisionId:   $decisionId,
                activatedAt:  $status === AnnualFeeScheduleStatus::Active ? $now : null,
                activatedBy:  $status === AnnualFeeScheduleStatus::Active ? $in->actor->id : null,
                supersededAt: null,
                createdAt:    $now,
                createdBy:    $in->actor->id,
            );
            $this->schedules->save($schedule);
            $scheduleIds[] = $schedule->id()->value();
        }

        return new DraftAnnualFeeScheduleOutput($scheduleIds, $decisionId);
    }
}
