<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryTenantMembershipSubTierRepository implements TenantMembershipSubTierRepositoryInterface
{
    /** @var array<string, TenantMembershipSubTier> id-keyed */
    private array $byId = [];

    public function listForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();
        $out = [];
        foreach ($this->byId as $st) {
            if ($st->tenantId->value() === $tid) {
                $out[] = $st;
            }
        }
        usort($out, static fn($a, $b) => $a->appliesTo->value <=> $b->appliesTo->value
            ?: $a->rankOrder <=> $b->rankOrder);
        return $out;
    }

    public function findBySlug(
        TenantId $tenantId,
        MembershipType $appliesTo,
        string $slug,
    ): ?TenantMembershipSubTier {
        foreach ($this->byId as $st) {
            if ($st->tenantId->value() === $tenantId->value()
                && $st->appliesTo === $appliesTo
                && $st->slug === $slug) {
                return $st;
            }
        }
        return null;
    }

    public function save(TenantMembershipSubTier $subTier): void
    {
        $this->byId[$subTier->id->value()] = $subTier;
    }

    public function delete(TenantMembershipSubTierId $id): void
    {
        unset($this->byId[$id->value()]);
    }
}
