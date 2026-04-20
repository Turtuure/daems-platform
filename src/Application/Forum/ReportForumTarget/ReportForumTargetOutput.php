<?php

declare(strict_types=1);

namespace Daems\Application\Forum\ReportForumTarget;

final class ReportForumTargetOutput
{
    public function __construct(public readonly bool $success) {}
}
