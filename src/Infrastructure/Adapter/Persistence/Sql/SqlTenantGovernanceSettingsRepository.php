<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Governance\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PDO;

final class SqlTenantGovernanceSettingsRepository implements TenantGovernanceSettingsRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(TenantId $tenantId): ?TenantGovernanceSettings
    {
        $stmt = $this->pdo->prepare(
            'SELECT tenant_id, expulsion_hearing_days, decision_expiration_days
               FROM tenant_governance_settings WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) return null;
        $tid = is_string($r['tenant_id'] ?? null) ? $r['tenant_id'] : throw new \DomainException('Corrupt tenant_governance_settings.tenant_id');
        $ehRaw = $r['expulsion_hearing_days']   ?? null;
        $deRaw = $r['decision_expiration_days'] ?? null;
        $eh = is_int($ehRaw) ? $ehRaw : (is_string($ehRaw) ? (int) $ehRaw : 14);
        $de = is_int($deRaw) ? $deRaw : (is_string($deRaw) ? (int) $deRaw : 60);
        return new TenantGovernanceSettings(TenantId::fromString($tid), $eh, $de);
    }

    public function save(TenantGovernanceSettings $s): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_governance_settings (tenant_id, expulsion_hearing_days, decision_expiration_days)
             VALUES (:tid, :eh, :de)
             ON DUPLICATE KEY UPDATE
                expulsion_hearing_days   = VALUES(expulsion_hearing_days),
                decision_expiration_days = VALUES(decision_expiration_days)'
        );
        $stmt->execute([
            ':tid' => $s->tenantId->value(),
            ':eh'  => $s->expulsionHearingDays,
            ':de'  => $s->decisionExpirationDays,
        ]);
    }
}
