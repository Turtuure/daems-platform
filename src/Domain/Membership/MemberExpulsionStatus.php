<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

enum MemberExpulsionStatus: string
{
    case Hearing       = 'hearing';
    case AwaitingVote  = 'awaiting_vote';
    case Expelled      = 'expelled';
    case Rejected      = 'rejected';
    case Appealed      = 'appealed';
}
