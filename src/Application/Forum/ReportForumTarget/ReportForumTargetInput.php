<?php

declare(strict_types=1);

namespace Daems\Application\Forum\ReportForumTarget;

use Daems\Domain\Auth\ActingUser;

final class ReportForumTargetInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
        public readonly string $reasonCategory,
        public readonly ?string $reasonDetail,
    ) {}
}
