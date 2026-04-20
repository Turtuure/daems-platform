<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\ConflictException;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: delete a forum category.
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load existing category or NotFound
 *  - If any topics exist in category → ConflictException('category_has_topics')
 *  - Delete via deleteCategoryForTenant
 *  - Audit action='category_deleted' with original_payload
 */
final class DeleteForumCategoryAsAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(DeleteForumCategoryAsAdminInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $existing = $this->forum->findCategoryByIdForTenant($in->id, $tenantId);
        if ($existing === null) {
            throw new NotFoundException('category_not_found');
        }

        if ($this->forum->countTopicsInCategoryForTenant($in->id, $tenantId) > 0) {
            throw new ConflictException('category_has_topics');
        }

        $this->forum->deleteCategoryForTenant($in->id, $tenantId);

        $now = new DateTimeImmutable();
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'category',
            $in->id,
            ForumModerationAuditEntry::ACTION_CATEGORY_DELETED,
            [
                'slug'        => $existing->slug(),
                'name'        => $existing->name(),
                'icon'        => $existing->icon(),
                'description' => $existing->description(),
                'sort_order'  => $existing->sortOrder(),
            ],
            null,
            null,
            $in->acting->id->value(),
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
