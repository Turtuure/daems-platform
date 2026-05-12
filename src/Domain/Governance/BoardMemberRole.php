<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardMemberRole: string
{
    case Chair  = 'chair';
    case Member = 'member';
}
