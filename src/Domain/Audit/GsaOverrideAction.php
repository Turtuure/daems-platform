<?php
declare(strict_types=1);

namespace Daems\Domain\Audit;

enum GsaOverrideAction: string
{
    case ForceApproveBasic = 'force_approve_basic';
}
