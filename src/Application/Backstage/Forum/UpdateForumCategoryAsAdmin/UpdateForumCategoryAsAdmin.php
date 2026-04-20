<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

/**
 * Admin-side use case: update an existing forum category (partial merge).
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Load existing category or NotFound
 *  - Merge provided fields onto existing, preserving counters
 *  - Persist via updateCategoryForTenant
 *  - Audit action='category_updated' with original_payload + new_payload
 */
final class UpdateForumCategoryAsAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(UpdateForumCategoryAsAdminInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $existing = $this->forum->findCategoryByIdForTenant($in->id, $tenantId);
        if ($existing === null) {
            throw new NotFoundException('category_not_found');
        }

        $updated = new ForumCategory(
            $existing->id(),
            $tenantId,
            $in->slug !== null ? trim($in->slug) : $existing->slug(),
            $in->name !== null ? trim($in->name) : $existing->name(),
            $in->icon ?? $existing->icon(),
            $in->description ?? $existing->description(),
            $in->sortOrder ?? $existing->sortOrder(),
            $existing->topicCount(),
            $existing->postCount(),
        );

        $this->forum->updateCategoryForTenant($updated);

        $now = new DateTimeImmutable();
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'category',
            $existing->id()->value(),
            ForumModerationAuditEntry::ACTION_CATEGORY_UPDATED,
            [
                'slug'        => $existing->slug(),
                'name'        => $existing->name(),
                'icon'        => $existing->icon(),
                'description' => $existing->description(),
                'sort_order'  => $existing->sortOrder(),
            ],
            [
                'slug'        => $updated->slug(),
                'name'        => $updated->name(),
                'icon'        => $updated->icon(),
                'description' => $updated->description(),
                'sort_order'  => $updated->sortOrder(),
            ],
            null,
            $in->acting->id->value(),
            null,
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
