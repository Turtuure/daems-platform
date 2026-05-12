<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryBoardRepository implements BoardRepositoryInterface
{
    /** @var array<string, Board> keyed by tenant_id */
    private array $byTenant = [];

    public function findForTenant(TenantId $tenantId): ?Board
    {
        return $this->byTenant[$tenantId->value()] ?? null;
    }

    public function save(Board $board): void
    {
        $this->byTenant[$board->tenantId->value()] = $board;
    }
}
