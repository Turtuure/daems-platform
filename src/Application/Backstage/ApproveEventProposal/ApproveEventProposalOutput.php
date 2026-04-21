<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ApproveEventProposal;

final class ApproveEventProposalOutput
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $slug,
    ) {
    }
}
