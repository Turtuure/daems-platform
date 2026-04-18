<?php

declare(strict_types=1);

namespace Daems\Application\Project\SubmitProjectProposal;

final class SubmitProjectProposalInput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly string $title,
        public readonly string $category,
        public readonly string $summary,
        public readonly string $description,
    ) {}
}
