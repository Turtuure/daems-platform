<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectParticipant;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Project\ProjectUpdate;

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

    public function lastComment(): ?ProjectComment
    {
        return $this->comments === [] ? null : $this->comments[array_key_last($this->comments)];
    }

    public function lastUpdate(): ?ProjectUpdate
    {
        return $this->updates === [] ? null : $this->updates[array_key_last($this->updates)];
    }

    public function findAll(?string $category = null, ?string $status = null, ?string $search = null): array
    {
        return array_values($this->bySlug);
    }

    public function findBySlug(string $slug): ?Project
    {
        return $this->bySlug[$slug] ?? null;
    }

    public function save(Project $project): void
    {
        $this->bySlug[$project->slug()] = $project;
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
            fn(array $p) => $p['user_id'] !== $userId,
        ));
    }

    public function findCommentsByProjectId(string $projectId): array
    {
        return array_values(array_filter($this->comments, fn(ProjectComment $c) => $c->projectId() === $projectId));
    }

    public function saveComment(ProjectComment $comment): void
    {
        $this->comments[] = $comment;
    }

    public function incrementCommentLikes(string $commentId): void
    {
        // noop for tests
    }

    public function findUpdatesByProjectId(string $projectId): array
    {
        return array_values(array_filter($this->updates, fn(ProjectUpdate $u) => $u->projectId() === $projectId));
    }

    public function saveUpdate(ProjectUpdate $update): void
    {
        $this->updates[] = $update;
    }
}
