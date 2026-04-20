<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use PDO;

final class SqlAdminApplicationDismissalRepository implements AdminApplicationDismissalRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(AdminApplicationDismissal $d): void
    {
        $sql = 'INSERT INTO admin_application_dismissals
                  (id, admin_id, app_id, app_type, dismissed_at)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE dismissed_at = VALUES(dismissed_at)';
        $this->pdo->prepare($sql)->execute([
            $d->id,
            $d->adminId,
            $d->appId,
            $d->appType,
            $d->dismissedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function deleteByAdminId(string $adminId): void
    {
        $this->pdo->prepare('DELETE FROM admin_application_dismissals WHERE admin_id = ?')
            ->execute([$adminId]);
    }

    public function deleteByAppId(string $appId): void
    {
        $this->pdo->prepare('DELETE FROM admin_application_dismissals WHERE app_id = ?')
            ->execute([$appId]);
    }

    public function listAppIdsDismissedByAdmin(string $adminId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT app_id FROM admin_application_dismissals WHERE admin_id = ?'
        );
        $stmt->execute([$adminId]);
        return array_values(array_map(
            static fn (array $r): string => (string) $r['app_id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        ));
    }
}
