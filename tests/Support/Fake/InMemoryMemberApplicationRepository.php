<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;

final class InMemoryMemberApplicationRepository implements MemberApplicationRepositoryInterface
{
    /** @var list<MemberApplication> */
    public array $applications = [];

    public function save(MemberApplication $application): void
    {
        $this->applications[] = $application;
    }
}
