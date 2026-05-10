<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PDO;

final class SqlUserDashboardRepository implements UserDashboardRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findFor(UserId $userId, TenantId $tenantId): ?UserDashboard
    {
        $stmt = $this->pdo->prepare(
            'SELECT layout, updated_at FROM user_dashboards WHERE user_id = :uid AND tenant_id = :tid'
        );
        $stmt->execute([
            ':uid' => $userId->value(),
            ':tid' => $tenantId->value(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $layoutRaw  = is_string($row['layout'])     ? $row['layout']     : throw new \UnexpectedValueException('Corrupt user_dashboards.layout');
        $updatedRaw = is_string($row['updated_at']) ? $row['updated_at'] : throw new \UnexpectedValueException('Corrupt user_dashboards.updated_at');

        /** @var array<int, array{widget_id: string, span: int}> $raw */
        $raw = json_decode($layoutRaw, true, 512, JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($raw as $r) {
            $entries[] = new LayoutEntry((string) $r['widget_id'], WidgetSpan::of((int) $r['span']));
        }

        return new UserDashboard(
            $userId,
            $tenantId,
            $entries,
            new \DateTimeImmutable($updatedRaw),
        );
    }

    public function save(UserDashboard $dashboard): void
    {
        $layoutJson = json_encode(
            array_map(fn(LayoutEntry $e) => $e->toArray(), $dashboard->layout()),
            JSON_THROW_ON_ERROR,
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_dashboards (id, user_id, tenant_id, layout, updated_at)
             VALUES (:id, :uid, :tid, :layout, :updated_at)
             ON DUPLICATE KEY UPDATE layout = VALUES(layout), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            ':id'         => Uuid7::generate()->value(),
            ':uid'        => $dashboard->userId()->value(),
            ':tid'        => $dashboard->tenantId()->value(),
            ':layout'     => $layoutJson,
            ':updated_at' => $dashboard->updatedAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(UserId $userId, TenantId $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_dashboards WHERE user_id = :uid AND tenant_id = :tid'
        );
        $stmt->execute([
            ':uid' => $userId->value(),
            ':tid' => $tenantId->value(),
        ]);
    }
}
