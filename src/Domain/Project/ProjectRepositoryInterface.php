<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

interface ProjectRepositoryInterface
{
    /** @return Project[] */
    public function listForTenant(TenantId $tenantId, ?string $category = null, ?string $status = null, ?string $search = null): array;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project;

    public function save(Project $project): void;

    public function deleteById(string $projectId): void;

    public function countParticipants(string $projectId): int;

    public function isParticipant(string $projectId, string $userId): bool;

    public function addParticipant(ProjectParticipant $participant): void;

    public function removeParticipant(string $projectId, string $userId): void;

    /** @return ProjectComment[] */
    public function findCommentsByProjectId(string $projectId): array;

    public function saveComment(ProjectComment $comment): void;

    public function incrementCommentLikes(string $commentId): void;

    /** @return ProjectUpdate[] */
    public function findUpdatesByProjectId(string $projectId): array;

    public function saveUpdate(ProjectUpdate $update): void;
}
