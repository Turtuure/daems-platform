<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DismissForumReport;

use Daems\Domain\Auth\ActingUser;

final class DismissForumReportInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly ?string $note = null,
    ) {}
}
