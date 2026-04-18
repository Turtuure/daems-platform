<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

interface MemberApplicationRepositoryInterface
{
    public function save(MemberApplication $application): void;
}
