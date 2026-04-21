<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;

interface ProjectRepositoryInterface
{
    /**
     * PUBLIC listing: only non-draft, non-archived-unless-asked projects, featured first.
     * @return Project[]
     */
    public function listForTenant(TenantId $tenantId, ?string $category = null, ?string $status = null, ?string $search = null): array;

    /**
     * ADMIN listing: all statuses, additional filters.
     * @param array{status?:string,category?:string,featured?:bool,q?:string} $filters
     * @return Project[]
     */
    public function listAllStatusesForTenant(TenantId $tenantId, array $filters = []): array;

    public function findByIdForTenant(string $id, TenantId $tenantId): ?Project;

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Project;

    public function save(Project $project): void;

    /** @param array<string,mixed> $fields */
    public function updateForTenant(string $id, TenantId $tenantId, array $fields): void;

    public function setStatusForTenant(string $id, TenantId $tenantId, string $status): void;

    public function setFeaturedForTenant(string $id, TenantId $tenantId, bool $featured): void;

    public function deleteById(string $projectId): void;

    public function countParticipants(string $projectId): int;

    public function isParticipant(string $projectId, string $userId): bool;

    public function addParticipant(ProjectParticipant $participant): void;

    public function removeParticipant(string $projectId, string $userId): void;

    /** @return ProjectComment[] */
    public function findCommentsByProjectId(string $projectId): array;

    /** @return list<array{comment_id:string,project_id:string,project_title:string,author_name:string,content:string,created_at:string}> */
    public function listRecentCommentsForTenant(TenantId $tenantId, int $limit = 100): array;

    public function saveComment(ProjectComment $comment): void;

    public function deleteCommentForTenant(string $commentId, TenantId $tenantId): void;

    public function incrementCommentLikes(string $commentId): void;

    /** @return ProjectUpdate[] */
    public function findUpdatesByProjectId(string $projectId): array;

    public function saveUpdate(ProjectUpdate $update): void;

    /**
     * Upsert one locale's translation row for a project within a tenant.
     * Throws \DomainException('project_not_found_in_tenant') if the project does not
     * belong to the provided tenant.
     *
     * @param array<string, ?string> $fields title + summary + description
     */
    public function saveTranslation(
        TenantId $tenantId,
        string $projectId,
        SupportedLocale $locale,
        array $fields,
    ): void;
}
