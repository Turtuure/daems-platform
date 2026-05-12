<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

/**
 * @phpstan-type RosterRow array{user_id:string, role:string, term_started_at:string, term_ends_at:string}
 */
final class BootstrapBoardInput
{
    /** @param list<array{user_id:string, role:string, term_started_at:string, term_ends_at:string}> $members */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId $gsaUserId,
        public readonly array $members,
        public readonly \DateTimeImmutable $at,
    ) {}
}
