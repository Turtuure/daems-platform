<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ResolveForumReportByEdit;

use Daems\Domain\Auth\ActingUser;

final class ResolveForumReportByEditInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly string $newContent,
        public readonly ?string $note = null,
    ) {}
}
