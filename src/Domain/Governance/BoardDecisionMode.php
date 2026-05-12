<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionMode: string
{
    case Async = 'async';
    case Sync  = 'sync';
}
