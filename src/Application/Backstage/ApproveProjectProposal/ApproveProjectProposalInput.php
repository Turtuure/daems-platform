<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ApproveProjectProposal;

use Daems\Domain\Auth\ActingUser;

final class ApproveProjectProposalInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $proposalId,
        public readonly ?string $note,
    ) {}
}
