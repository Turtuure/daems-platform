<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

enum ApplicationDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
