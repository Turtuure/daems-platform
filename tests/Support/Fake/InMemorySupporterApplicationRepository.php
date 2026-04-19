<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

final class InMemorySupporterApplicationRepository implements SupporterApplicationRepositoryInterface
{
    /** @var list<SupporterApplication> */
    public array $applications = [];

    public function save(SupporterApplication $application): void
    {
        $this->applications[] = $application;
    }
}
