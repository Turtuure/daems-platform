<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdmin;
use Daems\Application\Backstage\Forum\UpdateForumCategoryAsAdmin\UpdateForumCategoryAsAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class UpdateForumCategoryAsAdminTest extends TestCase
{
    private const TENANT_ID    = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID     = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID    = '01958000-0000-7000-8000-000000000a02';
    private const CATEGORY_ID  = '01958000-0000-7000-8000-00000000c001';
    private const UNKNOWN_ID   = '01958000-0000-7000-8000-00000000cfff';

    public function test_partial_update_merges_and_writes_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedCategory(
            $forum,
            $tenant,
            self::CATEGORY_ID,
            slug: 'general',
            name: 'General',
        );

        $uc = new UpdateForumCategoryAsAdmin($forum, $audit);
        $uc->execute(new UpdateForumCategoryAsAdminInput(
            acting: $admin,
            id: self::CATEGORY_ID,
            name: 'General Chat',
            sortOrder: 5,
        ));

        $stored = $forum->findCategoryBySlugForTenant('general', $tenant);
        self::assertNotNull($stored);
        self::assertSame('general', $stored->slug());
        self::assertSame('General Chat', $stored->name());
        self::assertSame('chat', $stored->icon());
        self::assertSame('Talk about anything', $stored->description());
        self::assertSame(5, $stored->sortOrder());

        self::assertCount(1, $audit->rows);
        $entry = $audit->rows[0];
        self::assertSame(ForumModerationAuditEntry::ACTION_CATEGORY_UPDATED, $entry->action());
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
        self::assertSame(
            [
                'slug'        => 'general',
                'name'        => 'General Chat',
                'icon'        => 'chat',
                'description' => 'Talk about anything',
                'sort_order'  => 5,
            ],
            $entry->newPayload(),
        );
        self::assertSame(self::ADMIN_ID, $entry->performedBy());
    }

    public function test_unknown_category_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new UpdateForumCategoryAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('category_not_found');

        $uc->execute(new UpdateForumCategoryAsAdminInput(
            acting: $admin,
            id: self::UNKNOWN_ID,
            name: 'Whatever',
        ));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $forum = new InMemoryForumRepository();
        ForumSeed::seedCategory($forum, $tenant, self::CATEGORY_ID);

        $uc = new UpdateForumCategoryAsAdmin($forum, new InMemoryForumModerationAuditRepository());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new UpdateForumCategoryAsAdminInput(
            acting: $member,
            id: self::CATEGORY_ID,
            name: 'Nope',
        ));
    }
}
