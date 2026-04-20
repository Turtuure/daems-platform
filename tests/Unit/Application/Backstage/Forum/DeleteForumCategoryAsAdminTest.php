<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdmin;
use Daems\Application\Backstage\Forum\DeleteForumCategoryAsAdmin\DeleteForumCategoryAsAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Shared\ConflictException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class DeleteForumCategoryAsAdminTest extends TestCase
{
    private const TENANT_ID   = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID    = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID   = '01958000-0000-7000-8000-000000000a02';
    private const CATEGORY_ID = '01958000-0000-7000-8000-00000000c001';
    private const TOPIC_ID    = '01958000-0000-7000-8000-000000010001';
    private const UNKNOWN_ID  = '01958000-0000-7000-8000-00000000cfff';

    public function test_deletes_empty_category_and_writes_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedCategory($forum, $tenant, self::CATEGORY_ID, slug: 'general', name: 'General');

        $uc = new DeleteForumCategoryAsAdmin($forum, $audit);
        $uc->execute(new DeleteForumCategoryAsAdminInput($admin, self::CATEGORY_ID));

        self::assertNull($forum->findCategoryBySlugForTenant('general', $tenant));

        self::assertCount(1, $audit->rows);
        $entry = $audit->rows[0];
        self::assertSame(ForumModerationAuditEntry::ACTION_CATEGORY_DELETED, $entry->action());
        self::assertSame('category', $entry->targetType());
        self::assertSame(self::CATEGORY_ID, $entry->targetId());
        self::assertSame(
            [
                'slug'        => 'general',
                'name'        => 'General',
                'icon'        => 'chat',
                'description' => 'Talk about anything',
                'sort_order'  => 1,
            ],
            $entry->originalPayload(),
        );
        self::assertNull($entry->newPayload());
        self::assertSame(self::ADMIN_ID, $entry->performedBy());
    }

    public function test_category_with_topics_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        ForumSeed::seedCategory($forum, $tenant, self::CATEGORY_ID);
        ForumSeed::seedTopic($forum, $tenant, self::TOPIC_ID, categoryId: self::CATEGORY_ID);

        $uc = new DeleteForumCategoryAsAdmin($forum, new InMemoryForumModerationAuditRepository());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('category_has_topics');

        $uc->execute(new DeleteForumCategoryAsAdminInput($admin, self::CATEGORY_ID));
    }

    public function test_unknown_category_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new DeleteForumCategoryAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('category_not_found');

        $uc->execute(new DeleteForumCategoryAsAdminInput($admin, self::UNKNOWN_ID));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $forum = new InMemoryForumRepository();
        ForumSeed::seedCategory($forum, $tenant, self::CATEGORY_ID);

        $uc = new DeleteForumCategoryAsAdmin($forum, new InMemoryForumModerationAuditRepository());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new DeleteForumCategoryAsAdminInput($member, self::CATEGORY_ID));
    }
}
