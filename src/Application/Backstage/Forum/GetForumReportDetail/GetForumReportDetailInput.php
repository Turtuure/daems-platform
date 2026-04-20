<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\GetForumReportDetail;

use Daems\Domain\Auth\ActingUser;

final class GetForumReportDetailInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $targetType,
        public readonly string $targetId,
    ) {}
}
