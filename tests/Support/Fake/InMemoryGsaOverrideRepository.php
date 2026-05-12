<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryGsaOverrideRepository implements GsaOverrideRepositoryInterface
{
    /** @var array<string, GsaOverride> */
    private array $byId = [];

    public function listForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->byId as $o) {
            if ($o->tenantId->value() === $tenantId->value()) $out[] = $o;
        }
        usort($out, fn(GsaOverride $a, GsaOverride $b) => $b->performedAt <=> $a->performedAt);
        return array_slice($out, 0, $limit);
    }

    public function save(GsaOverride $override): void
    {
        $this->byId[$override->id->value()] = $override;
    }
}
