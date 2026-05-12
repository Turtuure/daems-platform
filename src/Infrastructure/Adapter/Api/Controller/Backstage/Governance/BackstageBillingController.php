<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule;
use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeScheduleInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use InvalidArgumentException;

final class BackstageBillingController
{
    public function __construct(
        private readonly DraftAnnualFeeSchedule               $draft,
        private readonly AnnualFeeScheduleRepositoryInterface $schedules,
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
}
