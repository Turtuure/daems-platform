<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule;
use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeScheduleInput;
use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment;
use Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPaymentInput;
use Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice;
use Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoiceInput;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\ConfirmImportPayments;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\ConfirmImportPaymentsInput;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\PreviewImportPayments;
use Daems\Application\Membership\Billing\ImportPaymentsCsv\PreviewImportPaymentsInput;
use Daems\Application\Membership\Billing\ReverseLapse\ReverseLapse;
use Daems\Application\Membership\Billing\ReverseLapse\ReverseLapseInput;
use Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride;
use Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverrideInput;
use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride;
use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverrideInput;
use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice;
use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoiceInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Governance\Exception\GsaOverrideRequiresReason;
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
        private readonly WaiveMemberFeeInvoice                $waive,
        private readonly ReduceMemberFeeInvoice               $reduce,
        private readonly RecordManualPayment                  $markPaid,
        private readonly MemberFeeInvoiceRepositoryInterface  $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface   $audit,
        private readonly ReverseLapse                         $reverseLapse,
        private readonly PreviewImportPayments                $previewImport,
        private readonly ConfirmImportPayments                $confirmImport,
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

    public function listInvoices(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isAdminIn($actor->activeTenant) && !$actor->isPlatformAdmin) {
            throw new ForbiddenException('admin_required');
        }

        $filter = [];
        if (($v = $req->query('year'))     !== null && is_string($v)) { $filter['year']     = (int) $v; }
        if (($v = $req->query('status'))   !== null && is_string($v)) { $filter['status']   = $v; }
        if (($v = $req->query('fee_type')) !== null && is_string($v)) { $filter['fee_type'] = $v; }
        if (($v = $req->query('user_id'))  !== null && is_string($v)) { $filter['user_id']  = $v; }

        $pageRaw = $req->query('page');
        $page = is_string($pageRaw) || is_int($pageRaw) ? max(1, (int) $pageRaw) : 1;
        $perPage = 50;
        $rows = $this->invoices->listForTenant($actor->activeTenant, $filter, $perPage, ($page - 1) * $perPage);

        return Response::json([
            'page'    => $page,
            'filter'  => $filter,
            'rows'    => array_map(fn(MemberFeeInvoice $i) => $this->serializeInvoice($i), $rows),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function markInvoicePaid(Request $req, array $params): Response
    {
        $actor = $req->requireActingUser();
        $idRaw = $params['id'] ?? '';
        $body = $req->all();
        $amountRaw    = $body['amount_cents'] ?? null;
        $paidAtRaw    = $body['paid_at']      ?? null;
        $methodRaw    = $body['method']       ?? null;
        $referenceRaw = $body['reference']    ?? '';

        if (!is_string($paidAtRaw) || !is_string($methodRaw) || !(is_int($amountRaw) || is_string($amountRaw))) {
            return Response::json(['error' => 'amount_cents, paid_at, method are required'], 400);
        }

        try {
            $this->markPaid->handle(new RecordManualPaymentInput(
                actor:       $actor,
                invoiceId:   MemberFeeInvoiceId::fromString($idRaw),
                amountCents: (int) $amountRaw,
                paidAt:      new DateTimeImmutable($paidAtRaw),
                method:      $methodRaw,
                reference:   is_string($referenceRaw) ? $referenceRaw : '',
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvoiceAlreadyPaidException $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
        return Response::json(['marked_paid' => true]);
    }

    /**
     * @param array<string,string> $params
     */
    public function waiveInvoice(Request $req, array $params): Response
    {
        $actor = $req->requireActingUser();
        $idRaw = $params['id'] ?? '';
        $reasonRaw = $req->all()['reason'] ?? '';

        try {
            $this->waive->handle(new WaiveMemberFeeInvoiceInput(
                actor:     $actor,
                invoiceId: MemberFeeInvoiceId::fromString($idRaw),
                reason:    is_string($reasonRaw) ? $reasonRaw : '',
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
        return Response::json(['waived' => true]);
    }

    /**
     * @param array<string,string> $params
     */
    public function reduceInvoice(Request $req, array $params): Response
    {
        $actor = $req->requireActingUser();
        $idRaw = $params['id'] ?? '';
        $body = $req->all();
        $amountRaw = $body['amount_cents'] ?? null;
        $reasonRaw = $body['reason']       ?? '';

        if (!(is_int($amountRaw) || is_string($amountRaw))) {
            return Response::json(['error' => 'amount_cents is required'], 400);
        }

        try {
            $this->reduce->handle(new ReduceMemberFeeInvoiceInput(
                actor:          $actor,
                invoiceId:      MemberFeeInvoiceId::fromString($idRaw),
                newAmountCents: (int) $amountRaw,
                reason:         is_string($reasonRaw) ? $reasonRaw : '',
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
        return Response::json(['reduced' => true]);
    }

    public function billingKpi(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isAdminIn($actor->activeTenant) && !$actor->isPlatformAdmin) {
            throw new ForbiddenException('admin_required');
        }
        $year = $req->int('year') ?? (int) date('Y');

        // TODO(perf): replace with `SELECT status, COUNT(*) ... GROUP BY status` when row counts grow.
        $counts = [];
        foreach (['PENDING', 'OVERDUE', 'PAID', 'WAIVED', 'REDUCED'] as $st) {
            $rows = $this->invoices->listForTenant(
                $actor->activeTenant,
                ['year' => $year, 'status' => $st],
                9999,
                0,
            );
            $counts[$st] = count($rows);
        }
        return Response::json(['year' => $year, 'counts' => $counts]);
    }

    /**
     * @param array<string,string> $params
     */
    public function invoiceAudit(Request $req, array $params): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isAdminIn($actor->activeTenant) && !$actor->isPlatformAdmin) {
            throw new ForbiddenException('admin_required');
        }
        $idRaw = $params['id'] ?? '';
        $rows = $this->audit->listForInvoice(MemberFeeInvoiceId::fromString($idRaw));
        return Response::json([
            'rows' => array_map(static fn($r) => [
                'action'       => $r->action->value,
                'performed_by' => $r->performedBy?->value(),
                'performed_at' => $r->performedAt->format(\DateTimeImmutable::ATOM),
                'payload'      => $r->payloadJson !== null ? json_decode($r->payloadJson, true) : null,
            ], $rows),
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function reverseLapse(Request $req, array $params): Response
    {
        $actor = $req->requireActingUser();
        $idRaw = $params['id'] ?? '';
        $justificationRaw = $req->all()['justification'] ?? '';

        try {
            $this->reverseLapse->handle(new ReverseLapseInput(
                actor:         $actor,
                tenantId:      $actor->activeTenant,
                userId:        UserId::fromString($idRaw),
                justification: is_string($justificationRaw) ? $justificationRaw : '',
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (GsaOverrideRequiresReason $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
        return Response::json(['reversed' => true]);
    }

    public function previewImportCsv(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isAdminIn($actor->activeTenant) && !$actor->isPlatformAdmin) {
            return Response::json(['error' => 'admin_required'], 403);
        }

        $upload = $_FILES['csv'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'csv file required (multipart field "csv")'], 400);
        }
        $tmpPath = is_string($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : '';
        $content = $tmpPath !== '' ? (string) @file_get_contents($tmpPath) : '';

        try {
            $output = $this->previewImport->handle(new PreviewImportPaymentsInput(
                actor:      $actor,
                tenantId:   $actor->activeTenant,
                csvContent: $content,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        return Response::json([
            'high_confidence_count' => $output->highConfidenceCount(),
            'results' => array_map(static fn($r) => [
                'row_number'            => $r->parsed->rowNumber,
                'reference'             => $r->parsed->reference,
                'amount_cents'          => $r->parsed->amountCents,
                'value_date'            => $r->parsed->valueDate->format('Y-m-d'),
                'payer_name'            => $r->parsed->payerName,
                'matched_invoice_id'    => $r->matchedInvoiceId?->value(),
                'matched_amount_cents'  => $r->matchedAmountCents,
                'confidence'            => $r->confidence,
                'low_confidence_reason' => $r->reasonForLowConfidence,
            ], $output->results),
        ]);
    }

    public function confirmImportCsv(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $matchesRaw = $req->all()['matches'] ?? [];
        if (!is_array($matchesRaw)) {
            return Response::json(['error' => 'matches must be an array'], 400);
        }

        $matches = [];
        foreach ($matchesRaw as $m) {
            if (!is_array($m)) {
                continue;
            }
            $invoiceId = is_string($m['invoice_id'] ?? null) ? $m['invoice_id'] : null;
            $amount    = is_int($m['amount_cents'] ?? null) || (is_string($m['amount_cents'] ?? null) && ctype_digit((string) $m['amount_cents']))
                ? (int) $m['amount_cents']
                : null;
            $paidAt    = is_string($m['paid_at'] ?? null) ? $m['paid_at'] : null;
            $reference = is_string($m['reference'] ?? null) ? $m['reference'] : '';
            if ($invoiceId === null || $amount === null || $paidAt === null) {
                continue;
            }
            $matches[] = ['invoice_id' => $invoiceId, 'amount_cents' => $amount, 'paid_at' => $paidAt, 'reference' => $reference];
        }

        try {
            $output = $this->confirmImport->handle(new ConfirmImportPaymentsInput(
                actor:    $actor,
                tenantId: $actor->activeTenant,
                matches:  $matches,
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }
        return Response::json([
            'applied' => $output->applied,
            'errors'  => $output->errors,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeInvoice(MemberFeeInvoice $i): array
    {
        return [
            'id'                    => $i->id()->value(),
            'user_id'               => $i->userId()->value(),
            'year'                  => $i->year(),
            'fee_type'              => $i->feeType()->value,
            'anniversary_date'      => $i->anniversaryDate()->format('Y-m-d'),
            'amount_cents'          => $i->amountCents(),
            'original_amount_cents' => $i->originalAmountCents(),
            'currency'              => $i->currency(),
            'due_date'              => $i->dueDate()->format('Y-m-d'),
            'status'                => $i->status()->value,
            'paid_at'               => $i->paidAt()?->format(\DateTimeImmutable::ATOM),
            'paid_amount_cents'     => $i->paidAmountCents(),
            'paid_method'           => $i->paidMethod(),
            'paid_reference'        => $i->paidReference(),
            'waived_at'             => $i->waivedAt()?->format(\DateTimeImmutable::ATOM),
            'waive_reason'          => $i->waiveReason(),
            'override_id'           => $i->overrideId(),
        ];
    }
}
