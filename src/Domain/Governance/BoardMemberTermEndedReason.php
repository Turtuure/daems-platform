<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardMemberTermEndedReason: string
{
    case Resigned       = 'resigned';
    case Removed        = 'removed';
    case LostFullStatus = 'lost_full_status';
    case TermExpired    = 'term_expired';
}
