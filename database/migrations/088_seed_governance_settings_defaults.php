<?php
// 088_seed_governance_settings_defaults.php
// Seed default governance settings (14 vrk hearing + 60 vrk expiration) for
// every existing tenant. Idempotent — INSERT IGNORE keeps re-runs safe.
//
// @var \PDO $pdo  — provided by MigrationRunner / MigrationTestCase

$insert = $pdo->prepare(
    'INSERT IGNORE INTO tenant_governance_settings (tenant_id, expulsion_hearing_days, decision_expiration_days)
     VALUES (?, 14, 60)'
);
$tenantIds = $pdo->query('SELECT id FROM tenants')->fetchAll(\PDO::FETCH_COLUMN);
foreach ($tenantIds as $tenantId) {
    if (!is_string($tenantId)) continue;
    $insert->execute([$tenantId]);
}
