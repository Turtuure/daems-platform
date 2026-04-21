<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventProposalsForAdmin;

final class ListEventProposalsForAdminOutput
{
    /**
     * @param list<array<string, mixed>> $proposals
     */
    public function __construct(public readonly array $proposals)
    {
    }
}
