<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Backstage\PendingApplication;

final class ListPendingApplicationsOutput
{
    /**
     * @param list<PendingApplication> $member
     * @param list<PendingApplication> $supporter
     */
    public function __construct(
        public readonly array $member,
        public readonly array $supporter,
    ) {}

    /** @return array{member: list<array<string, string>>, supporter: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'member'    => array_map(static fn (PendingApplication $p) => $p->toArray(), $this->member),
            'supporter' => array_map(static fn (PendingApplication $p) => $p->toArray(), $this->supporter),
        ];
    }
}
