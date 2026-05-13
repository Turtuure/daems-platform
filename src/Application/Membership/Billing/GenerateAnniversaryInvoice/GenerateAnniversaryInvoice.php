<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice;

use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception\NoActiveFeeScheduleException;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock;
use Daems\Domain\User\UserRepositoryInterface;

final class GenerateAnniversaryInvoice
{
    public function __construct(
        private readonly UserRepositoryInterface                     $users,
        private readonly AnnualFeeScheduleRepositoryInterface        $schedules,
        private readonly UserFeeOverrideRepositoryInterface          $overrides,
        private readonly MemberFeeInvoiceRepositoryInterface         $invoices,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly Clock                                       $clock,
    ) {}

    public function handle(GenerateAnniversaryInvoiceInput $in): GenerateAnniversaryInvoiceOutput
    {
        $user = $this->users->findById($in->userId->value());
        if ($user === null) {
            throw new \DomainException("User not found: {$in->userId->value()}");
        }

        $rawType = $user->membershipType();
        $type = MembershipType::tryFrom($rawType);
        if ($type === null) {
            throw new \DomainException("Unknown membership type: {$rawType}");
        }
        if ($type === MembershipType::Honorary) {
            throw new \DomainException('HONORARY members do not receive invoices (§ 3)');
        }
        if ($user->membershipStatus() !== 'active') {
            throw new \DomainException("User membership_status is {$user->membershipStatus()}, expected active");
        }

        $today = $this->clock->now();
        $year = (int) $today->format('Y');

        $existing = $this->invoices->findFor($in->tenantId, $in->userId, $year);
        if ($existing !== null) {
            return new GenerateAnniversaryInvoiceOutput(
                created:     false,
                invoiceId:   $existing->id()->value(),
                amountCents: $existing->amountCents(),
                overrideId:  $existing->overrideId(),
            );
        }

        $schedule = $this->schedules->findActiveFor($in->tenantId, $year, $type);
        if ($schedule === null) {
            throw new NoActiveFeeScheduleException(
                "No active fee schedule for tenant {$in->tenantId->value()} year {$year} type {$type->value}"
            );
        }
        $amount = $schedule->amountCents();
        $overrideId = null;

        $override = $this->overrides->findActiveFor($in->tenantId, $in->userId, $type->value, $today);
        if ($override !== null) {
            $amount = $override->overrideAmountCents();
            $overrideId = $override->id()->value();
        }

        $tenantSettings = $this->settings->find($in->tenantId);
        $dueDays = $tenantSettings?->defaultDueDaysFromAnniversary() ?? 60;
        $dueDate = $today->modify("+{$dueDays} days")->setTime(0, 0, 0);
        $anniversary = $today->setTime(0, 0, 0);

        $invoice = new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $in->tenantId,
            userId:              $in->userId,
            year:                $year,
            feeType:             $type,
            anniversaryDate:     $anniversary,
            amountCents:         $amount,
            originalAmountCents: null,
            currency:            $schedule->currency(),
            dueDate:             $dueDate,
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          $overrideId,
            createdAt:           $today,
        );
        $this->invoices->save($invoice);

        return new GenerateAnniversaryInvoiceOutput(
            created:     true,
            invoiceId:   $invoice->id()->value(),
            amountCents: $amount,
            overrideId:  $overrideId,
        );
    }
}
