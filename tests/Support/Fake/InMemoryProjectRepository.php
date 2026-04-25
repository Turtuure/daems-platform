<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectParticipant;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Project\ProjectUpdate;
use Daems\Domain\Tenant\TenantId;

final class InMemoryProjectRepository implements ProjectRepositoryInterface
{
    /** @var array<string, Project> by slug */
    public array $bySlug = [];

    /** @var array<string, list<array{user_id:string}>> participants by project_id */
    public array $participants = [];

    /** @var list<ProjectComment> */
    public array $comments = [];

    /** @var list<ProjectUpdate> */
    public array $updates = [];

    /** @var array<string, array<string, array<string, ?string>>> translations keyed by projectId, then locale */
    public array $translations = [];

    public function lastComment(): ?ProjectComment
    {
        return $this->comments === [] ? null : $this->comments[array_key_last($this->comments)];
    }

    public function lastUpdate(): ?ProjectUpdate
    {
        return $this->updates === [] ? null : $this->updates[array_key_last($this->updates)];
    }

    public function listForTenant(TenantId $tenantId, ?string $category = null, ?string $status = null, ?string $search = null): array
    {
        $matches = [];
        foreach ($this->bySlug as $p) {
            if (!$p->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($status !== null) {
                if ($p->status() !== $status) {
                    continue;
                }
            } else {
                // Public: exclude drafts and archived
                if (in_array($p->status(), ['draft', 'archived'], true)) {
                    continue;
                }
            }
            if ($category !== null && $p->category() !== $category) {
                continue;
            }
            if ($search !== null && $search !== '') {
                $needle = strtolower($search);
                $hay = strtolower($p->title() . ' ' . $p->summary());
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $matches[] = $p;
        }
        self::sortFeaturedSortCreated($matches);
        return $matches;
    }

    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array
    {
        $matches = [];
        foreach ($this->bySlug as $p) {
            if (!$p->tenantId()->equals($tenantId)) {
                continue;
            }
            if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '' && $p->status() !== $filters['status']) {
                continue;
            }
            if (isset($filters['category']) && is_string($filters['category']) && $filters['category'] !== '' && $p->category() !== $filters['category']) {
                continue;
            }
            if (isset($filters['featured']) && $filters['featured'] === true && !$p->featured()) {
                continue;
            }
            if (isset($filters['q']) && is_string($filters['q']) && $filters['q'] !== '') {
                $needle = strtolower($filters['q']);
                $hay = strtolower($p->title() . ' ' . $p->summary());
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $matches[] = $p;
        }
        self::sortFeaturedSortCreated($matches);
        return $matches;
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Project
    {
        foreach ($this->bySlug as $p) {
            if ($p->id()->value() === $id && $p->tenantId()->equals($tenantId)) {
                return $p;
            }
        }
        return null;
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project
    {
        $project = $this->bySlug[$slug] ?? null;
        if ($project === null) {
            return null;
        }
        return $project->tenantId()->equals($tenantId) ? $project : null;
    }

    public function save(Project $project): void
    {
        $this->bySlug[$project->slug()] = $project;
    }

    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void
    {
        $allowed = ['title', 'category', 'icon', 'summary', 'description', 'status', 'sort_order', 'featured'];
        foreach ($this->bySlug as $slug => $p) {
            if ($p->id()->value() !== $id || !$p->tenantId()->equals($tenantId)) {
                continue;
            }
            $title = $p->title();
            $category = $p->category();
            $icon = $p->icon();
            $summary = $p->summary();
            $description = $p->description();
            $status = $p->status();
            $sortOrder = $p->sortOrder();
            $featured = $p->featured();
            foreach ($fields as $col => $val) {
                if (!is_string($col) || !in_array($col, $allowed, true)) {
                    continue;
                }
                switch ($col) {
                    case 'title':       if (is_string($val)) { $title = $val; } break;
                    case 'category':    if (is_string($val)) { $category = $val; } break;
                    case 'icon':        if (is_string($val)) { $icon = $val; } break;
                    case 'summary':     if (is_string($val)) { $summary = $val; } break;
                    case 'description': if (is_string($val)) { $description = $val; } break;
                    case 'status':      if (is_string($val)) { $status = $val; } break;
                    case 'sort_order':  if (is_int($val)) { $sortOrder = $val; } elseif (is_string($val) && is_numeric($val)) { $sortOrder = (int) $val; } break;
                    case 'featured':    $featured = (bool) $val; break;
                }
            }
            $this->bySlug[$slug] = new Project(
                $p->id(),
                $p->tenantId(),
                $p->slug(),
                $title,
                $category,
                $icon,
                $summary,
                $description,
                $status,
                $sortOrder,
                $p->ownerId(),
                $featured,
                $p->createdAt(),
            );
            return;
        }
    }

    public function setStatusForTenant(string $id, TenantId $tenantId, string $status): void
    {
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new \DomainException('invalid_project_status');
        }
        $this->updateForTenant($id, $tenantId, ['status' => $status]);
    }

    public function setFeaturedForTenant(string $id, TenantId $tenantId, bool $featured): void
    {
        $this->updateForTenant($id, $tenantId, ['featured' => $featured]);
    }

    public function deleteById(string $projectId): void
    {
        foreach ($this->bySlug as $slug => $p) {
            if ($p->id()->value() === $projectId) {
                unset($this->bySlug[$slug]);
            }
        }
    }

    public function countParticipants(string $projectId): int
    {
        return count($this->participants[$projectId] ?? []);
    }

    public function isParticipant(string $projectId, string $userId): bool
    {
        foreach ($this->participants[$projectId] ?? [] as $p) {
            if ($p['user_id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    public function addParticipant(ProjectParticipant $participant): void
    {
        $this->participants[$participant->projectId()][] = ['user_id' => $participant->userId()];
    }

    public function removeParticipant(string $projectId, string $userId): void
    {
        $this->participants[$projectId] = array_values(array_filter(
            $this->participants[$projectId] ?? [],
            static fn(array $p): bool => $p['user_id'] !== $userId,
        ));
    }

    public function findCommentsByProjectId(string $projectId): array
    {
        return array_values(array_filter($this->comments, static fn(ProjectComment $c): bool => $c->projectId() === $projectId));
    }

    public function listRecentCommentsForTenant(TenantId $tenantId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->comments as $c) {
            $project = $this->findProjectById($c->projectId());
            if ($project === null || !$project->tenantId()->equals($tenantId)) {
                continue;
            }
            $out[] = [
                'comment_id'    => $c->id()->value(),
                'project_id'    => $c->projectId(),
                'project_title' => $project->title(),
                'author_name'   => $c->authorName(),
                'content'       => $c->content(),
                'created_at'    => $c->createdAt(),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return array_slice($out, 0, $limit);
    }

    public function saveComment(ProjectComment $comment): void
    {
        $this->comments[] = $comment;
    }

    public function deleteCommentForTenant(string $commentId, TenantId $tenantId): void
    {
        $filtered = [];
        foreach ($this->comments as $c) {
            if ($c->id()->value() === $commentId) {
                $project = $this->findProjectById($c->projectId());
                if ($project !== null && $project->tenantId()->equals($tenantId)) {
                    continue; // drop
                }
            }
            $filtered[] = $c;
        }
        $this->comments = $filtered;
    }

    public function incrementCommentLikes(string $commentId): void
    {
        // noop for tests
    }

    public function findUpdatesByProjectId(string $projectId): array
    {
        return array_values(array_filter($this->updates, static fn(ProjectUpdate $u): bool => $u->projectId() === $projectId));
    }

    public function saveUpdate(ProjectUpdate $update): void
    {
        $this->updates[] = $update;
    }

    private function findProjectById(string $projectId): ?Project
    {
        foreach ($this->bySlug as $p) {
            if ($p->id()->value() === $projectId) {
                return $p;
            }
        }
        return null;
    }

    public function saveTranslation(
        TenantId $tenantId,
        string $projectId,
        SupportedLocale $locale,
        array $fields,
    ): void {
        $found = null;
        foreach ($this->bySlug as $p) {
            if ($p->id()->value() === $projectId && $p->tenantId()->equals($tenantId)) {
                $found = $p;
                break;
            }
        }
        if ($found === null) {
            throw new \DomainException('project_not_found_in_tenant');
        }
        $newRow = [
            'title'       => isset($fields['title']) ? (string) $fields['title'] : '',
            'summary'     => isset($fields['summary']) ? (string) $fields['summary'] : '',
            'description' => isset($fields['description']) ? (string) $fields['description'] : '',
        ];
        $this->translations[$projectId][$locale->value()] = $newRow;

        // Rebuild a fresh TranslationMap merging existing rows with the new write,
        // matching SQL repo semantics where hydrate() reads from projects_i18n.
        $merged = $found->translations()->raw();
        $merged[$locale->value()] = $newRow;
        $newMap = new TranslationMap($merged);

        $convenience = self::firstAvailableRow($newMap);
        $updated = new Project(
            $found->id(), $found->tenantId(), $found->slug(),
            $convenience['title'], $found->category(), $found->icon(),
            $convenience['summary'], $convenience['description'],
            $found->status(), $found->sortOrder(),
            $found->ownerId(), $found->featured(), $found->createdAt(),
            $newMap,
        );
        $this->bySlug[$updated->slug()] = $updated;
    }

    public function statsForTenant(TenantId $tenantId): array
    {
        $active = 0;
        $drafts = 0;
        $featured = 0;

        foreach ($this->bySlug as $p) {
            if (!$p->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($p->status() === 'active') {
                $active++;
                if ($p->featured()) {
                    $featured++;
                }
            } elseif ($p->status() === 'draft') {
                $drafts++;
            }
        }

        // Zero-fill 30-day backward sparkline; bucket today's totals into the last entry.
        $base = new \DateTimeImmutable('today');
        $activeSpark = [];
        $draftSpark = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = $base->modify("-{$i} days")->format('Y-m-d');
            $activeSpark[] = ['date' => $date, 'value' => $i === 0 ? $active : 0];
            $draftSpark[]  = ['date' => $date, 'value' => $i === 0 ? $drafts : 0];
        }

        return [
            'active'   => ['value' => $active,   'sparkline' => $activeSpark],
            'drafts'   => ['value' => $drafts,   'sparkline' => $draftSpark],
            'featured' => ['value' => $featured, 'sparkline' => []],
        ];
    }

    /**
     * Mirror SqlProjectRepository::firstAvailable for each translatable field.
     * @return array{title: string, summary: string, description: string}
     */
    private static function firstAvailableRow(TranslationMap $translations): array
    {
        $out = ['title' => '', 'summary' => '', 'description' => ''];
        foreach (Project::TRANSLATABLE_FIELDS as $field) {
            $value = null;
            foreach ([SupportedLocale::UI_DEFAULT, SupportedLocale::CONTENT_FALLBACK] as $loc) {
                $row = $translations->rowFor(SupportedLocale::fromString($loc));
                if ($row !== null && isset($row[$field]) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                    $value = (string) $row[$field];
                    break;
                }
            }
            if ($value === null) {
                foreach (SupportedLocale::supportedValues() as $loc) {
                    $row = $translations->rowFor(SupportedLocale::fromString($loc));
                    if ($row !== null && isset($row[$field]) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                        $value = (string) $row[$field];
                        break;
                    }
                }
            }
            $out[$field] = $value ?? '';
        }
        return $out;
    }

    /**
     * @param list<Project> $projects
     * @param-out list<Project> $projects
     */
    private static function sortFeaturedSortCreated(array &$projects): void
    {
        usort($projects, static function (Project $a, Project $b): int {
            $fa = $a->featured() ? 1 : 0;
            $fb = $b->featured() ? 1 : 0;
            if ($fa !== $fb) {
                return $fb - $fa; // featured first
            }
            if ($a->sortOrder() !== $b->sortOrder()) {
                return $a->sortOrder() - $b->sortOrder();
            }
            return strcmp($b->createdAt(), $a->createdAt());
        });
    }
}
