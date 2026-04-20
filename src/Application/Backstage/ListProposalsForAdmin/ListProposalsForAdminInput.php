<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProposalsForAdmin;

use Daems\Domain\Auth\ActingUser;

final class ListProposalsForAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
    ) {}
}
