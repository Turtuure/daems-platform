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
            'SELECT tenant_id, expulsion_hearing_days, decision_expiration_days,
                    requires_formal_decision_for_fees, default_due_days_from_anniversary,
                    overdue_grace_days, lapse_check_enabled
               FROM tenant_governance_settings WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId->value()]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) return null;
        $tid = is_string($r['tenant_id'] ?? null) ? $r['tenant_id'] : throw new \DomainException('Corrupt tenant_governance_settings.tenant_id');
        $ehRaw  = $r['expulsion_hearing_days']   ?? null;
        $deRaw  = $r['decision_expiration_days'] ?? null;
        $eh = is_int($ehRaw) ? $ehRaw : (is_string($ehRaw) ? (int) $ehRaw : 14);
        $de = is_int($deRaw) ? $deRaw : (is_string($deRaw) ? (int) $deRaw : 60);
        $rfdfRaw = $r['requires_formal_decision_for_fees'] ?? null;
        $dddRaw  = $r['default_due_days_from_anniversary'] ?? null;
        $ogdRaw  = $r['overdue_grace_days']                ?? null;
        $lceRaw  = $r['lapse_check_enabled']               ?? null;
        $rfdf = is_int($rfdfRaw) ? (bool) $rfdfRaw : (is_string($rfdfRaw) ? (bool)(int) $rfdfRaw : false);
        $ddd  = is_int($dddRaw)  ? $dddRaw          : (is_string($dddRaw)  ? (int) $dddRaw         : 60);
        $ogd  = is_int($ogdRaw)  ? $ogdRaw          : (is_string($ogdRaw)  ? (int) $ogdRaw         : 30);
        $lce  = is_int($lceRaw)  ? (bool) $lceRaw   : (is_string($lceRaw)  ? (bool)(int) $lceRaw   : true);
        return new TenantGovernanceSettings(
            tenantId:                          TenantId::fromString($tid),
            expulsionHearingDays:              $eh,
            decisionExpirationDays:            $de,
            requiresFormalDecisionForFees:     $rfdf,
            defaultDueDaysFromAnniversary:     $ddd,
            overdueGraceDays:                  $ogd,
            lapseCheckEnabled:                 $lce,
        );
    }

    public function save(TenantGovernanceSettings $s): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_governance_settings
                (tenant_id, expulsion_hearing_days, decision_expiration_days,
                 requires_formal_decision_for_fees, default_due_days_from_anniversary,
                 overdue_grace_days, lapse_check_enabled)
             VALUES (:tid, :eh, :de, :rfdf, :ddd, :ogd, :lce)
             ON DUPLICATE KEY UPDATE
                expulsion_hearing_days              = VALUES(expulsion_hearing_days),
                decision_expiration_days            = VALUES(decision_expiration_days),
                requires_formal_decision_for_fees   = VALUES(requires_formal_decision_for_fees),
                default_due_days_from_anniversary   = VALUES(default_due_days_from_anniversary),
                overdue_grace_days                  = VALUES(overdue_grace_days),
                lapse_check_enabled                 = VALUES(lapse_check_enabled)'
        );
        $stmt->execute([
            ':tid'  => $s->tenantId->value(),
            ':eh'   => $s->expulsionHearingDays,
            ':de'   => $s->decisionExpirationDays,
            ':rfdf' => (int) $s->requiresFormalDecisionForFees(),
            ':ddd'  => $s->defaultDueDaysFromAnniversary(),
            ':ogd'  => $s->overdueGraceDays(),
            ':lce'  => (int) $s->lapseCheckEnabled(),
        ]);
    }
}
