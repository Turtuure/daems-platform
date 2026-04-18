<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

interface SupporterApplicationRepositoryInterface
{
    public function save(SupporterApplication $application): void;
}
