<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

/**
 * Lifecycle of a (tenant, year, fee_type) fee row.
 *
 * draft      — admin started editing, not submitted
 * proposed   — admin submitted; awaiting board decision (if requires_formal_decision_for_fees=1)
 * active     — current canonical price for that (tenant, year, fee_type)
 * superseded — replaced by a newer row (kept for audit; never deleted)
 */
enum AnnualFeeScheduleStatus: string
{
    case Draft      = 'draft';
    case Proposed   = 'proposed';
    case Active     = 'active';
    case Superseded = 'superseded';
}
