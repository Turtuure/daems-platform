<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ActingUser;

final class ListPendingApplicationsForAdminInput
{
    public function __construct(public readonly ActingUser $acting) {}
}
