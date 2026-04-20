<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ResolveForumReportByWarn;

use Daems\Domain\Auth\ActingUser;

final class ResolveForumReportByWarnInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly ?string $note,
    ) {}
}
