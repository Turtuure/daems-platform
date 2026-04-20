<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdmin;
use Daems\Application\Backstage\Forum\CreateForumCategoryAsAdmin\CreateForumCategoryAsAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Shared\ConflictException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateForumCategoryAsAdminTest extends TestCase
{
    private const TENANT_ID = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID = '01958000-0000-7000-8000-000000000a02';

    public function test_creates_category_and_writes_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        $uc = new CreateForumCategoryAsAdmin($forum, $audit);
        $out = $uc->execute(new CreateForumCategoryAsAdminInput(
            acting: $admin,
            slug: 'announcements',
            name: 'Announcements',
            icon: 'megaphone',
            description: 'Platform-wide news',
            sortOrder: 2,
        ));

        self::assertNotSame('', $out->id);
        self::assertSame('announcements', $out->slug);

        $stored = $forum->findCategoryBySlugForTenant('announcements', $tenant);
        self::assertNotNull($stored);
        self::assertSame('Announcements', $stored->name());
        self::assertSame('megaphone', $stored->icon());
        self::assertSame('Platform-wide news', $stored->description());
        self::assertSame(2, $stored->sortOrder());
        self::assertSame(0, $stored->topicCount());
        self::assertSame(0, $stored->postCount());

        self::assertCount(1, $audit->rows);
        $entry = $audit->rows[0];
        self::assertSame(ForumModerationAuditEntry::ACTION_CATEGORY_CREATED, $entry->action());
        self::assertSame('category', $entry->targetType());
        self::assertSame($out->id, $entry->targetId());
        self::assertNull($entry->originalPayload());
        self::assertSame(
            [
                'slug'        => 'announcements',
                'name'        => 'Announcements',
                'icon'        => 'megaphone',
                'description' => 'Platform-wide news',
                'sort_order'  => 2,
            ],
            $entry->newPayload(),
        );
        self::assertSame(self::ADMIN_ID, $entry->performedBy());
    }

    public function test_empty_slug_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new CreateForumCategoryAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('slug_required');

        $uc->execute(new CreateForumCategoryAsAdminInput(
            acting: $admin,
            slug: '   ',
            name: 'X',
        ));
    }

    public function test_duplicate_slug_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        ForumSeed::seedCategory($forum, $tenant, ForumSeed::DEFAULT_CATEGORY_ID, slug: 'general', name: 'General');

        $uc = new CreateForumCategoryAsAdmin($forum, new InMemoryForumModerationAuditRepository());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('slug_taken');

        $uc->execute(new CreateForumCategoryAsAdminInput(
            acting: $admin,
            slug: 'general',
            name: 'Duplicate',
        ));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $uc = new CreateForumCategoryAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new CreateForumCategoryAsAdminInput(
            acting: $member,
            slug: 'news',
            name: 'News',
        ));
    }
}
