<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectCommentModerationAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class ProjectCommentModerationIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $adminId;
    private string $authorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(46);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'proj-mod', 'Project Moderation Test']);

        $this->adminId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $this->adminId, 'Admin User', 'admin-mod@test.com',
            password_hash('pass1234', PASSWORD_BCRYPT), '1980-01-01',
        ]);
        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->adminId, $this->tenantId, 'admin']);

        $this->authorUserId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $this->authorUserId, 'Comment Author', 'author@test.com',
            password_hash('pass1234', PASSWORD_BCRYPT), '1990-01-01',
        ]);
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($this->adminId),
            email:              'admin-mod@test.com',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString($this->tenantId),
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    public function test_delete_comment_removes_row_and_writes_audit(): void
    {
        // Seed project
        $projectId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO projects
                (id, tenant_id, slug, title, category, icon, summary, description, status, sort_order, featured)
             VALUES (?, ?, 'mod-project', 'Moderated Project', 'community', 'bi-folder', 'summary text',
                     'longer description for moderation test', 'active', 0, 0)"
        )->execute([$projectId, $this->tenantId]);

        // Seed comment
        $commentId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO project_comments
                (id, tenant_id, project_id, user_id, author_name, avatar_initials, avatar_color, content, likes, created_at)
             VALUES (?, ?, ?, ?, 'Comment Author', 'CA', '#abc', 'naughty content', 0, '2026-04-20 10:00:00')"
        )->execute([$commentId, $this->tenantId, $projectId, $this->authorUserId]);

        $projectRepo = new SqlProjectRepository($this->conn);
        $auditRepo   = new SqlProjectCommentModerationAuditRepository($this->conn);
        $clockAt = new DateTimeImmutable('2026-04-20T12:00:00Z');
        $clock = new class ($clockAt) implements Clock {
            public function __construct(private readonly DateTimeImmutable $at) {}
            public function now(): DateTimeImmutable { return $this->at; }
        };
        $ids = new class implements IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };

        $uc = new DeleteProjectCommentAsAdmin($projectRepo, $auditRepo, $clock, $ids);
        $uc->execute(new DeleteProjectCommentAsAdminInput(
            $this->actingAdmin(), $projectId, $commentId, 'spam',
        ));

        // Row removed from project_comments
        $check = $this->pdo()->prepare('SELECT COUNT(*) FROM project_comments WHERE id = ?');
        $check->execute([$commentId]);
        self::assertSame(0, (int) $check->fetchColumn());

        // Audit row recorded
        $audit = $this->pdo()->prepare(
            'SELECT tenant_id, project_id, comment_id, action, reason, performed_by
             FROM project_comment_moderation_audit
             WHERE comment_id = ?'
        );
        $audit->execute([$commentId]);
        $row = $audit->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame($this->tenantId, $row['tenant_id']);
        self::assertSame($projectId, $row['project_id']);
        self::assertSame($commentId, $row['comment_id']);
        self::assertSame('deleted', $row['action']);
        self::assertSame('spam', $row['reason']);
        self::assertSame($this->adminId, $row['performed_by']);
    }
}
