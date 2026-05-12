<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionSubTierCrudOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
