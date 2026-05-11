<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Membership\TenantMembershipSubTierRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PDO;

final class SqlTenantMembershipSubTierRepository implements TenantMembershipSubTierRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForTenant(TenantId $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, slug, name, rank_order, applies_to
               FROM tenant_membership_subtiers
              WHERE tenant_id = ?
           ORDER BY applies_to ASC, rank_order ASC'
        );
        $stmt->execute([$tenantId->value()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function findBySlug(TenantId $tenantId, MembershipType $appliesTo, string $slug): ?TenantMembershipSubTier
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, slug, name, rank_order, applies_to
               FROM tenant_membership_subtiers
              WHERE tenant_id = ? AND applies_to = ? AND slug = ?'
        );
        $stmt->execute([$tenantId->value(), $appliesTo->value, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(TenantMembershipSubTier $subTier): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_membership_subtiers
                 (id, tenant_id, slug, name, rank_order, applies_to)
             VALUES (:id, :tid, :slug, :name, :rank, :applies)
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 rank_order = VALUES(rank_order)'
        );
        $stmt->execute([
            ':id'      => $subTier->id->value(),
            ':tid'     => $subTier->tenantId->value(),
            ':slug'    => $subTier->slug,
            ':name'    => $subTier->name,
            ':rank'    => $subTier->rankOrder,
            ':applies' => $subTier->appliesTo->value,
        ]);
    }

    public function delete(TenantMembershipSubTierId $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tenant_membership_subtiers WHERE id = ?');
        $stmt->execute([$id->value()]);
    }

    /** @param array<mixed,mixed> $row */
    private function hydrate(array $row): TenantMembershipSubTier
    {
        $id        = is_string($row['id']         ?? null) ? $row['id']         : throw new \DomainException('Corrupt tenant_membership_subtiers.id');
        $tenantId  = is_string($row['tenant_id']  ?? null) ? $row['tenant_id']  : throw new \DomainException('Corrupt tenant_membership_subtiers.tenant_id');
        $slug      = is_string($row['slug']       ?? null) ? $row['slug']       : throw new \DomainException('Corrupt tenant_membership_subtiers.slug');
        $name      = is_string($row['name']       ?? null) ? $row['name']       : throw new \DomainException('Corrupt tenant_membership_subtiers.name');
        $rank      = is_int($row['rank_order']    ?? null) || (is_string($row['rank_order'] ?? null) && ctype_digit((string) $row['rank_order']))
            ? (int) $row['rank_order']
            : throw new \DomainException('Corrupt tenant_membership_subtiers.rank_order');
        $appliesTo = is_string($row['applies_to'] ?? null) ? $row['applies_to'] : throw new \DomainException('Corrupt tenant_membership_subtiers.applies_to');

        return new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::fromString($id),
            tenantId:   TenantId::fromString($tenantId),
            slug:       $slug,
            name:       $name,
            rankOrder:  $rank,
            appliesTo:  MembershipType::from($appliesTo),
        );
    }
}
