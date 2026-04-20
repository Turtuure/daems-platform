<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Tenant\TenantSlugResolverInterface;
use PDO;

final class SqlTenantSlugResolver implements TenantSlugResolverInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function slugFor(string $tenantId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && isset($row['slug']) && is_string($row['slug']) ? $row['slug'] : null;
    }
}
