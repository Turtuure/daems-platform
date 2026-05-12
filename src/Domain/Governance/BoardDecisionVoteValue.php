<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionVoteValue: string
{
    case Yes     = 'yes';
    case No      = 'no';
    case Abstain = 'abstain';
}
