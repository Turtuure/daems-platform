<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectParticipant;
use Daems\Domain\Project\ProjectParticipantId;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Project\ProjectUpdate;
use Daems\Domain\Project\ProjectUpdateId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findAll(?string $category = null, ?string $status = null, ?string $search = null): array
    {
        $where  = [];
        $params = [];

        if ($category !== null) {
            $where[]  = 'category = ?';
            $params[] = $category;
        }
        if ($status !== null) {
            $where[]  = 'status = ?';
            $params[] = $status;
        } else {
            $where[] = "status != 'archived'";
        }
        if ($search !== null && $search !== '') {
            $where[]  = '(title LIKE ? OR summary LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql = 'SELECT * FROM projects';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sort_order ASC, title ASC';

        $rows = $params !== [] ? $this->db->query($sql, $params) : $this->db->query($sql);
        return array_map($this->hydrate(...), $rows);
    }

    public function findBySlug(string $slug): ?Project
    {
        $row = $this->db->queryOne('SELECT * FROM projects WHERE slug = ?', [$slug]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(Project $project): void
    {
        $this->db->execute(
            'INSERT INTO projects
                (id, owner_id, slug, title, category, icon, summary, description, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                owner_id    = VALUES(owner_id),
                title       = VALUES(title),
                category    = VALUES(category),
                icon        = VALUES(icon),
                summary     = VALUES(summary),
                description = VALUES(description),
                status      = VALUES(status),
                sort_order  = VALUES(sort_order)',
            [
                $project->id()->value(),
                $project->ownerId()?->value(),
                $project->slug(),
                $project->title(),
                $project->category(),
                $project->icon(),
                $project->summary(),
                $project->description(),
                $project->status(),
                $project->sortOrder(),
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
            'INSERT IGNORE INTO project_participants (id, project_id, user_id, joined_at)
             VALUES (?, ?, ?, ?)',
            [
                $participant->id()->value(),
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
                (id, project_id, user_id, author_name, avatar_initials, avatar_color, content, likes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $comment->id()->value(),
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
            'INSERT INTO project_updates (id, project_id, title, content, author_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $update->id()->value(),
                $update->projectId(),
                $update->title(),
                $update->content(),
                $update->authorName(),
                $update->createdAt(),
            ],
        );
    }

    private function hydrate(array $row): Project
    {
        return new Project(
            ProjectId::fromString($row['id']),
            $row['slug'],
            $row['title'],
            $row['category'],
            $row['icon'],
            $row['summary'],
            $row['description'],
            $row['status'],
            (int) $row['sort_order'],
            isset($row['owner_id']) && $row['owner_id'] !== null ? UserId::fromString($row['owner_id']) : null,
        );
    }

    private function hydrateComment(array $row): ProjectComment
    {
        return new ProjectComment(
            ProjectCommentId::fromString($row['id']),
            $row['project_id'],
            $row['user_id'],
            $row['author_name'],
            $row['avatar_initials'],
            $row['avatar_color'] ?? '',
            $row['content'],
            (int) $row['likes'],
            $row['created_at'],
        );
    }

    private function hydrateUpdate(array $row): ProjectUpdate
    {
        return new ProjectUpdate(
            ProjectUpdateId::fromString($row['id']),
            $row['project_id'],
            $row['title'],
            $row['content'],
            $row['author_name'],
            $row['created_at'],
        );
    }
}
