<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule;
use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeScheduleInput;
use Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride;
use Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverrideInput;
use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride;
use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverrideInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DateTimeImmutable;
use InvalidArgumentException;

final class BackstageBillingController
{
    public function __construct(
        private readonly DraftAnnualFeeSchedule               $draft,
        private readonly AnnualFeeScheduleRepositoryInterface $schedules,
        private readonly SetUserFeeOverride                   $setOverride,
        private readonly RevokeUserFeeOverride                $revokeOverride,
        private readonly UserFeeOverrideRepositoryInterface   $overrides,
    ) {}

    public function listFeeSchedules(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isAdminIn($actor->activeTenant)) {
            throw new ForbiddenException('admin_required');
        }

        $year = $req->int('year') ?? (int) date('Y');
        $rows = $this->schedules->listForTenantYear($actor->activeTenant, $year);

        $serialized = [];
        foreach ($rows as $r) {
            $serialized[] = [
                'id'           => $r->id()->value(),
                'fee_type'     => $r->feeType()->value,
                'amount_cents' => $r->amountCents(),
                'currency'     => $r->currency(),
                'status'       => $r->status()->value,
                'decision_id'  => $r->decisionId(),
                'activated_at' => $r->activatedAt()?->format(\DateTimeInterface::ATOM),
                'created_at'   => $r->createdAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return Response::json(['year' => $year, 'rows' => $serialized]);
    }

    public function createFeeSchedule(Request $req): Response
    {
        $actor = $req->requireActingUser();
        // Note: authorization is enforced by the use case itself (which throws
        // ForbiddenException). Forwarding the exception here means a single
        // source of truth — no duplicate admin-check at the HTTP layer.

        $body = $req->all();
        $yearRaw = $body['year'] ?? null;
        $feesRaw = $body['fees'] ?? null;
        $year = is_int($yearRaw) ? $yearRaw : (is_string($yearRaw) ? (int) $yearRaw : 0);
        $fees = [];
        if (is_array($feesRaw)) {
            foreach ($feesRaw as $key => $val) {
                if (!is_string($key)) continue;
                $fees[$key] = is_int($val) ? $val : (is_string($val) ? (int) $val : 0);
            }
        }

        try {
            $output = $this->draft->handle(new DraftAnnualFeeScheduleInput(
                actor:    $actor,
                tenantId: $actor->activeTenant,
                year:     $year,
                fees:     $fees,
            ));
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        // ForbiddenException propagates up — the framework converts it to a 403.

        return Response::json([
            'schedule_ids' => $output->scheduleIds,
            'decision_id'  => $output->decisionId,
        ], 201);
    }

    public function listOverrides(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isAdminIn($actor->activeTenant) && !$actor->isPlatformAdmin) {
            throw new ForbiddenException('admin_required');
        }

        $activeOnly = $req->query('active_only') === '1';
        $rows = $this->overrides->listForTenant($actor->activeTenant, activeOnly: $activeOnly);

        $serialized = [];
        foreach ($rows as $o) {
            $serialized[] = [
                'id'                    => $o->id()->value(),
                'user_id'               => $o->userId()->value(),
                'fee_type'              => $o->feeType()->value,
                'override_amount_cents' => $o->overrideAmountCents(),
                'valid_from'            => $o->validFrom()->format('Y-m-d'),
                'valid_until'           => $o->validUntil()?->format('Y-m-d'),
                'reason'                => $o->reason(),
                'decision_id'           => $o->decisionId(),
                'created_by'            => $o->createdBy()->value(),
                'created_at'            => $o->createdAt()->format(\DateTimeInterface::ATOM),
                'revoked_at'            => $o->revokedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }
        return Response::json(['active_only' => $activeOnly, 'rows' => $serialized]);
    }

    public function createOverride(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $body = $req->all();

        $userIdRaw     = $body['user_id']               ?? null;
        $feeTypeRaw    = $body['fee_type']              ?? null;
        $amountRaw     = $body['override_amount_cents'] ?? null;
        $validFromRaw  = $body['valid_from']            ?? null;
        $validUntilRaw = $body['valid_until']           ?? null;
        $reasonRaw     = $body['reason']                ?? null;
        $decisionIdRaw = $body['decision_id']           ?? null;

        if (!is_string($userIdRaw) || !is_string($feeTypeRaw) || !is_string($validFromRaw) || !is_string($reasonRaw)) {
            return Response::json(['error' => 'user_id, fee_type, valid_from, reason are required strings'], 400);
        }
        $amount = is_int($amountRaw) ? $amountRaw : (is_string($amountRaw) ? (int) $amountRaw : null);
        if ($amount === null) {
            return Response::json(['error' => 'override_amount_cents must be an int'], 400);
        }
        $validUntil = is_string($validUntilRaw) && $validUntilRaw !== '' ? new DateTimeImmutable($validUntilRaw) : null;
        $decisionId = is_string($decisionIdRaw) && $decisionIdRaw !== '' ? $decisionIdRaw : null;

        try {
            $id = $this->setOverride->handle(new SetUserFeeOverrideInput(
                actor:               $actor,
                tenantId:            $actor->activeTenant,
                userId:              UserId::fromString($userIdRaw),
                feeType:             $feeTypeRaw,
                overrideAmountCents: $amount,
                validFrom:           new DateTimeImmutable($validFromRaw),
                validUntil:          $validUntil,
                reason:              $reasonRaw,
                decisionId:          $decisionId,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException | \ValueError $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        return Response::json(['id' => $id], 201);
    }

    /**
     * @param array<string,string> $params
     */
    public function revokeOverride(Request $req, array $params): Response
    {
        $actor = $req->requireActingUser();
        $idRaw = $params['id'] ?? '';

        try {
            $this->revokeOverride->handle(new RevokeUserFeeOverrideInput(
                actor:      $actor,
                overrideId: UserFeeOverrideId::fromString($idRaw),
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        return Response::json(['revoked' => true]);
    }
}
