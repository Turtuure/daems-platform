<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin;

use DateTimeImmutable;
use InvalidArgumentException;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Forum\ForumModerationAuditRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Shared\ConflictException;

/**
 * Admin-side use case: create a new forum category.
 *
 * Flow:
 *  - Enforce admin/platform-admin
 *  - Validate slug + name non-empty
 *  - Reject duplicate slug for tenant (ConflictException)
 *  - Persist category with counters=0
 *  - Audit action='category_created' with new_payload
 */
final class CreateForumCategoryAsAdmin
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly ForumModerationAuditRepositoryInterface $audit,
    ) {}

    public function execute(CreateForumCategoryAsAdminInput $in): CreateForumCategoryAsAdminOutput
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $slug = trim($in->slug);
        $name = trim($in->name);
        if ($slug === '') {
            throw new InvalidArgumentException('slug_required');
        }
        if ($name === '') {
            throw new InvalidArgumentException('name_required');
        }

        if ($this->forum->findCategoryBySlugForTenant($slug, $tenantId) !== null) {
            throw new ConflictException('slug_taken');
        }

        $cat = new ForumCategory(
            ForumCategoryId::generate(),
            $tenantId,
            $slug,
            $name,
            $in->icon,
            $in->description,
            $in->sortOrder,
            0,
            0,
        );
        $this->forum->saveCategory($cat);

        $now = new DateTimeImmutable();
        $this->audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::generate(),
            $tenantId,
            'category',
            $cat->id()->value(),
            ForumModerationAuditEntry::ACTION_CATEGORY_CREATED,
            null,
            [
                'slug'        => $slug,
                'name'        => $name,
                'icon'        => $in->icon,
                'description' => $in->description,
                'sort_order'  => $in->sortOrder,
            ],
            null,
            $in->acting->id->value(),
            null,
            $now->format('Y-m-d H:i:s'),
        ));

        return new CreateForumCategoryAsAdminOutput($cat->id()->value(), $slug);
    }
}
