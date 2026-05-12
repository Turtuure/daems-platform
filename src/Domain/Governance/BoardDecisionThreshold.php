<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionThreshold: string
{
    case Unanimous = 'unanimous';
    case Majority  = 'majority';
}
