<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlMemberApplicationRepository implements MemberApplicationRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(MemberApplication $app): void
    {
        $this->db->execute(
            'INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $app->id()->value(),
                $app->tenantId()->value(),
                $app->name(),
                $app->email(),
                $app->dateOfBirth(),
                $app->country(),
                $app->motivation(),
                $app->howHeard(),
                $app->status(),
            ],
        );
    }

    public function listPendingForTenant(TenantId $tenantId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM member_applications
             WHERE tenant_id = ? AND status = 'pending'
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId->value());
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $r): MemberApplication => $this->hydrate($r), $rows);
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication
    {
        $row = $this->db->queryOne(
            'SELECT * FROM member_applications WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        UserId $decidedBy,
        ?string $note,
        \DateTimeImmutable $decidedAt,
    ): void {
        $this->db->execute(
            'UPDATE member_applications
                SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
              WHERE id = ? AND tenant_id = ?',
            [
                $decision,
                $decidedAt->format('Y-m-d H:i:s'),
                $decidedBy->value(),
                $note,
                $id,
                $tenantId->value(),
            ],
        );

        // Audit entry to member_register_audit (application-scoped)
        $this->db->execute(
            'INSERT INTO member_register_audit (id, tenant_id, application_id, action, performed_by, note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
                $tenantId->value(),
                $id,
                $decision,
                $decidedBy->value(),
                $note,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): MemberApplication
    {
        $country  = $row['country'] ?? null;
        $howHeard = $row['how_heard'] ?? null;

        return new MemberApplication(
            MemberApplicationId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'name'),
            self::str($row, 'email'),
            self::str($row, 'date_of_birth'),
            is_string($country) ? $country : null,
            self::str($row, 'motivation'),
            is_string($howHeard) ? $howHeard : null,
            self::str($row, 'status'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }
}
