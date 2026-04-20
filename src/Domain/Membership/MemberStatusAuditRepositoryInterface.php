<?php

declare(strict_types=1);

namespace Daems\Domain\Membership;

interface MemberStatusAuditRepositoryInterface
{
    public function save(MemberStatusAudit $audit): void;
}
