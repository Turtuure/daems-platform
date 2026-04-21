<?php

declare(strict_types=1);

namespace Daems\Application\Event\SubmitEventProposal;

use Daems\Domain\Auth\ActingUser;

final class SubmitEventProposalInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $title,
        public readonly string $eventDate,
        public readonly ?string $eventTime,
        public readonly ?string $location,
        public readonly bool $isOnline,
        public readonly string $description,
        public readonly ?string $sourceLocale = null,
    ) {
    }
}
