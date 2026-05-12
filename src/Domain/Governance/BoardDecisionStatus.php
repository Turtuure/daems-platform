<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionStatus: string
{
    case Pending    = 'pending';
    case Passed     = 'passed';
    case Rejected   = 'rejected';
    case Expired    = 'expired';
    case Withdrawn  = 'withdrawn';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
