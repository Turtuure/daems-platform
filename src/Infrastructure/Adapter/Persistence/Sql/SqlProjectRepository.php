<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
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
use Daems\Infrastructure\Adapter\Persistence\Sql\Concerns\DailyStatsHelpers;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectRepository implements ProjectRepositoryInterface
{
    use DailyStatsHelpers;

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
            $where[]  = 'EXISTS (SELECT 1 FROM projects_i18n pi WHERE pi.project_id = projects.id AND (pi.title LIKE ? OR pi.summary LIKE ?))';
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
            $sql .= ' AND EXISTS (SELECT 1 FROM projects_i18n pi WHERE pi.project_id = projects.id AND (pi.title LIKE ? OR pi.summary LIKE ?))';
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
        $allowed = ['category', 'icon', 'status', 'sort_order', 'featured'];
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
            'SELECT pc.id AS comment_id, pc.project_id AS project_id,
                    COALESCE(
                        (SELECT title FROM projects_i18n WHERE project_id = p.id AND locale = ?),
                        (SELECT title FROM projects_i18n WHERE project_id = p.id AND locale = ?),
                        (SELECT title FROM projects_i18n WHERE project_id = p.id LIMIT 1),
                        ""
                    ) AS project_title,
                    pc.author_name AS author_name, pc.content AS content,
                    DATE_FORMAT(pc.created_at, "%Y-%m-%d %H:%i:%s") AS created_at
             FROM project_comments pc
             JOIN projects p ON p.id = pc.project_id
             WHERE p.tenant_id = ?
             ORDER BY pc.created_at DESC
             LIMIT ' . (int) $limit,
            [SupportedLocale::UI_DEFAULT, SupportedLocale::CONTENT_FALLBACK, $tenantId->value()],
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
                (id, tenant_id, owner_id, slug, category, icon, status, sort_order, featured)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                owner_id    = VALUES(owner_id),
                category    = VALUES(category),
                icon        = VALUES(icon),
                status      = VALUES(status),
                sort_order  = VALUES(sort_order),
                featured    = VALUES(featured)',
            [
                $project->id()->value(),
                $project->tenantId()->value(),
                $project->ownerId()?->value(),
                $project->slug(),
                $project->category(),
                $project->icon(),
                $project->status(),
                $project->sortOrder(),
                $project->featured() ? 1 : 0,
            ],
        );

        foreach ($project->translations()->raw() as $locale => $row) {
            if ($row === null || !SupportedLocale::isSupported($locale)) {
                continue;
            }
            $this->upsertTranslationRow($project->id()->value(), $locale, $row);
        }
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

        $projectId = self::str($row, 'id');
        $translations = $this->loadTranslationMap($projectId);

        return new Project(
            ProjectId::fromString($projectId),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'slug'),
            self::firstAvailable($translations, 'title') ?? '',
            self::str($row, 'category'),
            self::str($row, 'icon'),
            self::firstAvailable($translations, 'summary') ?? '',
            self::firstAvailable($translations, 'description') ?? '',
            self::str($row, 'status'),
            self::asStatsInt($row, 'sort_order'),
            is_string($ownerIdRaw) && $ownerIdRaw !== '' ? UserId::fromString($ownerIdRaw) : null,
            (bool) (is_int($featuredRaw) ? $featuredRaw : (is_string($featuredRaw) && is_numeric($featuredRaw) ? (int) $featuredRaw : 0)),
            self::strOrDefault($row, 'created_at', ''),
            $translations,
        );
    }

    /**
     * Build TranslationMap from projects_i18n rows. After A11 (migration 054)
     * projects_i18n is the sole source of truth — no legacy-column fallback.
     */
    private function loadTranslationMap(string $projectId): TranslationMap
    {
        $rows = $this->db->query(
            'SELECT locale, title, summary, description FROM projects_i18n WHERE project_id = ?',
            [$projectId],
        );
        $map = [];
        foreach (SupportedLocale::supportedValues() as $loc) {
            $map[$loc] = null;
        }
        foreach ($rows as $r) {
            $loc = isset($r['locale']) && is_string($r['locale']) ? $r['locale'] : null;
            if ($loc === null || !SupportedLocale::isSupported($loc)) {
                continue;
            }
            $map[$loc] = [
                'title'       => isset($r['title']) && is_string($r['title']) ? $r['title'] : '',
                'summary'     => isset($r['summary']) && is_string($r['summary']) ? $r['summary'] : '',
                'description' => isset($r['description']) && is_string($r['description']) ? $r['description'] : '',
            ];
        }
        return new TranslationMap($map);
    }

    private static function firstAvailable(TranslationMap $translations, string $field): ?string
    {
        foreach ([SupportedLocale::UI_DEFAULT, SupportedLocale::CONTENT_FALLBACK] as $loc) {
            $row = $translations->rowFor(SupportedLocale::fromString($loc));
            if ($row !== null && isset($row[$field]) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                return (string) $row[$field];
            }
        }
        foreach (SupportedLocale::supportedValues() as $loc) {
            $row = $translations->rowFor(SupportedLocale::fromString($loc));
            if ($row !== null && isset($row[$field]) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                return (string) $row[$field];
            }
        }
        return null;
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
            self::asStatsInt($row, 'likes'),
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

    public function saveTranslation(
        TenantId $tenantId,
        string $projectId,
        SupportedLocale $locale,
        array $fields,
    ): void {
        $exists = $this->db->queryOne(
            'SELECT 1 FROM projects WHERE id = ? AND tenant_id = ?',
            [$projectId, $tenantId->value()],
        );
        if ($exists === null) {
            throw new \DomainException('project_not_found_in_tenant');
        }
        $this->upsertTranslationRow(
            $projectId,
            $locale->value(),
            [
                'title'       => (string) ($fields['title'] ?? ''),
                'summary'     => (string) ($fields['summary'] ?? ''),
                'description' => (string) ($fields['description'] ?? ''),
            ],
        );
    }

    public function statsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: active + drafts + featured counts.
        $totals = $this->db->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_n,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) AS draft_n,
                COALESCE(SUM(CASE WHEN status = 'active' AND featured = 1 THEN 1 ELSE 0 END), 0) AS featured_n
             FROM projects
             WHERE tenant_id = ?",
            [$tid],
        ) ?? [];

        // Active sparkline: BACKWARD 30 days by DATE(created_at), filtered to status='active'.
        $activeRows = $this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM projects
              WHERE tenant_id = ?
                AND status = 'active'
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)",
            [$tid],
        );
        $activeSpark = self::buildDailySeries30dBackward($activeRows);

        // Drafts sparkline: same window, status='draft'.
        $draftRows = $this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM projects
              WHERE tenant_id = ?
                AND status = 'draft'
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)",
            [$tid],
        );
        $draftSpark = self::buildDailySeries30dBackward($draftRows);

        return [
            'active' => [
                'value'     => self::asStatsInt($totals, 'active_n'),
                'sparkline' => $activeSpark,
            ],
            'drafts' => [
                'value'     => self::asStatsInt($totals, 'draft_n'),
                'sparkline' => $draftSpark,
            ],
            'featured' => [
                'value'     => self::asStatsInt($totals, 'featured_n'),
                'sparkline' => [], // curation toggle has no temporal series
            ],
        ];
    }

    /** @param array<string, ?string> $row */
    private function upsertTranslationRow(string $projectId, string $locale, array $row): void
    {
        $this->db->execute(
            'INSERT INTO projects_i18n (project_id, locale, title, summary, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title), summary=VALUES(summary),
                description=VALUES(description), updated_at=CURRENT_TIMESTAMP',
            [
                $projectId,
                $locale,
                (string) ($row['title'] ?? ''),
                (string) ($row['summary'] ?? ''),
                (string) ($row['description'] ?? ''),
            ],
        );
    }
}
