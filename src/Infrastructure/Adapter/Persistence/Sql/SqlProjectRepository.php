<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectParticipant;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Project\ProjectUpdate;
use Daems\Domain\Project\ProjectUpdateId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function listForTenant(TenantId $tenantId, ?string $category = null, ?string $status = null, ?string $search = null): array
    {
        $where  = ['tenant_id = ?'];
        $params = [$tenantId->value()];

        if ($category !== null) {
            $where[]  = 'category = ?';
            $params[] = $category;
        }
        if ($status !== null) {
            $where[]  = 'status = ?';
            $params[] = $status;
        } else {
            $where[] = "status NOT IN ('archived','draft')";
        }
        if ($search !== null && $search !== '') {
            $where[]  = '(title LIKE ? OR summary LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql = 'SELECT * FROM projects WHERE ' . implode(' AND ', $where) . ' ORDER BY featured DESC, sort_order ASC, created_at DESC';
        $rows = $this->db->query($sql, $params);
        return array_map($this->hydrate(...), $rows);
    }

    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
    {
        $sql = 'SELECT * FROM projects WHERE tenant_id = ?';
        $params = [$tenantId->value()];
        if (isset($filters['status']) && $filters['status'] !== '') {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['category']) && $filters['category'] !== '') {
            $sql .= ' AND category = ?';
            $params[] = $filters['category'];
        }
        if (isset($filters['featured']) && $filters['featured'] === true) {
            $sql .= ' AND featured = 1';
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $sql .= ' AND (title LIKE ? OR summary LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        $sql .= ' ORDER BY featured DESC, sort_order ASC, created_at DESC';
        return array_map($this->hydrate(...), $this->db->query($sql, $params));
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Project
    {
        $row = $this->db->queryOne(
            'SELECT * FROM projects WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $allowed = ['title', 'category', 'icon', 'summary', 'description', 'status', 'sort_order', 'featured'];
        $sets = [];
        $params = [];
        foreach ($fields as $col => $val) {
            if (!is_string($col) || !in_array($col, $allowed, true)) {
                continue;
            }
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $params[] = $tenantId->value();
        $this->db->execute(
            'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?',
            $params,
        );
    }

    public function setStatusForTenant(string $id, TenantId $tenantId, string $status): void
    {
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new \DomainException('invalid_project_status');
        }
        $this->db->execute(
            'UPDATE projects SET status = ? WHERE id = ? AND tenant_id = ?',
            [$status, $id, $tenantId->value()],
        );
    }

    public function setFeaturedForTenant(string $id, TenantId $tenantId, bool $featured): void
    {
        $this->db->execute(
            'UPDATE projects SET featured = ? WHERE id = ? AND tenant_id = ?',
            [$featured ? 1 : 0, $id, $tenantId->value()],
        );
    }

    public function listRecentCommentsForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $rows = $this->db->query(
            'SELECT pc.id AS comment_id, pc.project_id AS project_id, p.title AS project_title,
                    pc.author_name AS author_name, pc.content AS content,
                    DATE_FORMAT(pc.created_at, "%Y-%m-%d %H:%i:%s") AS created_at
             FROM project_comments pc
             JOIN projects p ON p.id = pc.project_id
             WHERE p.tenant_id = ?
             ORDER BY pc.created_at DESC
             LIMIT ' . (int) $limit,
            [$tenantId->value()],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'comment_id'    => self::str($row, 'comment_id'),
                'project_id'    => self::str($row, 'project_id'),
                'project_title' => self::str($row, 'project_title'),
                'author_name'   => self::str($row, 'author_name'),
                'content'       => self::str($row, 'content'),
                'created_at'    => self::str($row, 'created_at'),
            ];
        }
        return $out;
    }

    public function deleteCommentForTenant(string $commentId, TenantId $tenantId): void
    {
        $this->db->execute(
            'DELETE pc FROM project_comments pc
             JOIN projects p ON p.id = pc.project_id
             WHERE pc.id = ? AND p.tenant_id = ?',
            [$commentId, $tenantId->value()],
        );
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project
    {
        $row = $this->db->queryOne('SELECT * FROM projects WHERE slug = ? AND tenant_id = ?', [$slug, $tenantId->value()]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(Project $project): void
    {
        $this->db->execute(
            'INSERT INTO projects
                (id, tenant_id, owner_id, slug, title, category, icon, summary, description, status, sort_order, featured)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                owner_id    = VALUES(owner_id),
                title       = VALUES(title),
                category    = VALUES(category),
                icon        = VALUES(icon),
                summary     = VALUES(summary),
                description = VALUES(description),
                status      = VALUES(status),
                sort_order  = VALUES(sort_order),
                featured    = VALUES(featured)',
            [
                $project->id()->value(),
                $project->tenantId()->value(),
                $project->ownerId()?->value(),
                $project->slug(),
                $project->title(),
                $project->category(),
                $project->icon(),
                $project->summary(),
                $project->description(),
                $project->status(),
                $project->sortOrder(),
                $project->featured() ? 1 : 0,
            ],
        );
    }

    public function deleteById(string $projectId): void
    {
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$projectId]);
    }

    public function countParticipants(string $projectId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS cnt FROM project_participants WHERE project_id = ?',
            [$projectId],
        );
        $cnt = $row['cnt'] ?? null;
        return is_int($cnt) ? $cnt : (is_string($cnt) && is_numeric($cnt) ? (int) $cnt : 0);
    }

    public function isParticipant(string $projectId, string $userId): bool
    {
        $row = $this->db->queryOne(
            'SELECT 1 FROM project_participants WHERE project_id = ? AND user_id = ?',
            [$projectId, $userId],
        );
        return $row !== null;
    }

    public function addParticipant(ProjectParticipant $participant): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO project_participants (id, tenant_id, project_id, user_id, joined_at)
             VALUES (?, (SELECT tenant_id FROM projects WHERE id = ?), ?, ?, ?)',
            [
                $participant->id()->value(),
                $participant->projectId(),
                $participant->projectId(),
                $participant->userId(),
                $participant->joinedAt(),
            ],
        );
    }

    public function removeParticipant(string $projectId, string $userId): void
    {
        $this->db->execute(
            'DELETE FROM project_participants WHERE project_id = ? AND user_id = ?',
            [$projectId, $userId],
        );
    }

    public function findCommentsByProjectId(string $projectId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM project_comments WHERE project_id = ? ORDER BY created_at ASC',
            [$projectId],
        );
        return array_map($this->hydrateComment(...), $rows);
    }

    public function saveComment(ProjectComment $comment): void
    {
        $this->db->execute(
            'INSERT INTO project_comments
                (id, tenant_id, project_id, user_id, author_name, avatar_initials, avatar_color, content, likes, created_at)
             VALUES (?, (SELECT tenant_id FROM projects WHERE id = ?), ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $comment->id()->value(),
                $comment->projectId(),
                $comment->projectId(),
                $comment->userId(),
                $comment->authorName(),
                $comment->avatarInitials(),
                $comment->avatarColor(),
                $comment->content(),
                $comment->likes(),
                $comment->createdAt(),
            ],
        );
    }

    public function incrementCommentLikes(string $commentId): void
    {
        $this->db->execute(
            'UPDATE project_comments SET likes = likes + 1 WHERE id = ?',
            [$commentId],
        );
    }

    public function findUpdatesByProjectId(string $projectId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM project_updates WHERE project_id = ? ORDER BY created_at DESC',
            [$projectId],
        );
        return array_map($this->hydrateUpdate(...), $rows);
    }

    public function saveUpdate(ProjectUpdate $update): void
    {
        $this->db->execute(
            'INSERT INTO project_updates (id, tenant_id, project_id, title, content, author_name, created_at)
             VALUES (?, (SELECT tenant_id FROM projects WHERE id = ?), ?, ?, ?, ?, ?)',
            [
                $update->id()->value(),
                $update->projectId(),
                $update->projectId(),
                $update->title(),
                $update->content(),
                $update->authorName(),
                $update->createdAt(),
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Project
    {
        $ownerIdRaw = $row['owner_id'] ?? null;
        $featuredRaw = $row['featured'] ?? 0;

        return new Project(
            ProjectId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'slug'),
            self::str($row, 'title'),
            self::str($row, 'category'),
            self::str($row, 'icon'),
            self::str($row, 'summary'),
            self::str($row, 'description'),
            self::str($row, 'status'),
            self::intOf($row, 'sort_order'),
            is_string($ownerIdRaw) && $ownerIdRaw !== '' ? UserId::fromString($ownerIdRaw) : null,
            (bool) (is_int($featuredRaw) ? $featuredRaw : (is_string($featuredRaw) && is_numeric($featuredRaw) ? (int) $featuredRaw : 0)),
            self::strOrDefault($row, 'created_at', ''),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateComment(array $row): ProjectComment
    {
        return new ProjectComment(
            ProjectCommentId::fromString(self::str($row, 'id')),
            self::str($row, 'project_id'),
            self::str($row, 'user_id'),
            self::str($row, 'author_name'),
            self::str($row, 'avatar_initials'),
            self::strOrDefault($row, 'avatar_color', ''),
            self::str($row, 'content'),
            self::intOf($row, 'likes'),
            self::str($row, 'created_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateUpdate(array $row): ProjectUpdate
    {
        return new ProjectUpdate(
            ProjectUpdateId::fromString(self::str($row, 'id')),
            self::str($row, 'project_id'),
            self::str($row, 'title'),
            self::str($row, 'content'),
            self::str($row, 'author_name'),
            self::str($row, 'created_at'),
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

    /** @param array<string, mixed> $row */
    private static function strOrDefault(array $row, string $key, string $default): string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @param array<string, mixed> $row */
    private static function intOf(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }
}
