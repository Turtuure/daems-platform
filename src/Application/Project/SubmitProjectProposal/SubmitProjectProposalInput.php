<?php

declare(strict_types=1);

namespace Daems\Application\Project\SubmitProjectProposal;

use Daems\Domain\Auth\ActingUser;

final class SubmitProjectProposalInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $title,
        public readonly string $category,
        public readonly string $summary,
        public readonly string $description,
        public readonly ?string $sourceLocale = null,
    ) {}
}
