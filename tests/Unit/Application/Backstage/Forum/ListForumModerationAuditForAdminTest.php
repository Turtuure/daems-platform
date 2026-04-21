<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdmin;
use Daems\Application\Backstage\Forum\ListForumModerationAuditForAdmin\ListForumModerationAuditForAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumModerationAuditEntry;
use Daems\Domain\Forum\ForumModerationAuditId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use PHPUnit\Framework\TestCase;

final class ListForumModerationAuditForAdminTest extends TestCase
{
    private const TENANT_ID   = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID    = '01958000-0000-7000-8000-000000000a01';
    private const REGISTERED  = '01958000-0000-7000-8000-000000000a02';

    private const AUDIT_ID_1  = '01958000-0000-7000-8000-0000000a1001';
    private const AUDIT_ID_2  = '01958000-0000-7000-8000-0000000a1002';
    private const AUDIT_ID_3  = '01958000-0000-7000-8000-0000000a1003';

    private const TARGET_POST_1 = '01958000-0000-7000-8000-00000000b001';
    private const TARGET_POST_2 = '01958000-0000-7000-8000-00000000b002';
    private const TARGET_TOPIC_1 = '01958000-0000-7000-8000-00000000c001';

    private const PERFORMER_ID = '01958000-0000-7000-8000-000000000a01';

    public function test_returns_recent_entries(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $audit  = new InMemoryForumModerationAuditRepository();

        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_1),
            $tenant,
            'post',
            self::TARGET_POST_1,
            ForumModerationAuditEntry::ACTION_DELETED,
            null,
            null,
            'spam',
            self::PERFORMER_ID,
            null,
            '2026-04-20T09:00:00+00:00',
        ));
        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_2),
            $tenant,
            'post',
            self::TARGET_POST_2,
            ForumModerationAuditEntry::ACTION_EDITED,
            null,
            null,
            null,
            self::PERFORMER_ID,
            null,
            '2026-04-20T10:00:00+00:00',
        ));
        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_3),
            $tenant,
            'topic',
            self::TARGET_TOPIC_1,
            ForumModerationAuditEntry::ACTION_PINNED,
            null,
            null,
            null,
            self::PERFORMER_ID,
            null,
            '2026-04-20T11:00:00+00:00',
        ));

        $uc  = new ListForumModerationAuditForAdmin($audit);
        $out = $uc->execute(new ListForumModerationAuditForAdminInput($admin));

        self::assertCount(3, $out->entries);
        // DESC by createdAt
        self::assertSame(self::AUDIT_ID_3, $out->entries[0]->id()->value());
        self::assertSame(self::AUDIT_ID_2, $out->entries[1]->id()->value());
        self::assertSame(self::AUDIT_ID_1, $out->entries[2]->id()->value());
    }

    public function test_filters_by_action(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $audit  = new InMemoryForumModerationAuditRepository();

        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_1),
            $tenant,
            'post',
            self::TARGET_POST_1,
            ForumModerationAuditEntry::ACTION_DELETED,
            null,
            null,
            'spam',
            self::PERFORMER_ID,
            null,
            '2026-04-20T09:00:00+00:00',
        ));
        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_2),
            $tenant,
            'topic',
            self::TARGET_TOPIC_1,
            ForumModerationAuditEntry::ACTION_PINNED,
            null,
            null,
            null,
            self::PERFORMER_ID,
            null,
            '2026-04-20T10:00:00+00:00',
        ));

        $uc  = new ListForumModerationAuditForAdmin($audit);
        $out = $uc->execute(new ListForumModerationAuditForAdminInput(
            $admin,
            200,
            ['action' => ForumModerationAuditEntry::ACTION_DELETED],
        ));

        self::assertCount(1, $out->entries);
        self::assertSame(ForumModerationAuditEntry::ACTION_DELETED, $out->entries[0]->action());
        self::assertSame(self::AUDIT_ID_1, $out->entries[0]->id()->value());
    }

    public function test_offset_pages_past_first_slice(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);
        $audit  = new InMemoryForumModerationAuditRepository();

        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_1),
            $tenant,
            'post',
            self::TARGET_POST_1,
            ForumModerationAuditEntry::ACTION_DELETED,
            null,
            null,
            null,
            self::PERFORMER_ID,
            null,
            '2026-04-20T09:00:00+00:00',
        ));
        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_2),
            $tenant,
            'post',
            self::TARGET_POST_2,
            ForumModerationAuditEntry::ACTION_EDITED,
            null,
            null,
            null,
            self::PERFORMER_ID,
            null,
            '2026-04-20T10:00:00+00:00',
        ));
        $audit->record(new ForumModerationAuditEntry(
            ForumModerationAuditId::fromString(self::AUDIT_ID_3),
            $tenant,
            'topic',
            self::TARGET_TOPIC_1,
            ForumModerationAuditEntry::ACTION_PINNED,
            null,
            null,
            null,
            self::PERFORMER_ID,
            null,
            '2026-04-20T11:00:00+00:00',
        ));

        $uc = new ListForumModerationAuditForAdmin($audit);

        // limit=2, offset=0 → newest two
        $page1 = $uc->execute(new ListForumModerationAuditForAdminInput($admin, 2, [], 0));
        self::assertCount(2, $page1->entries);
        self::assertSame(self::AUDIT_ID_3, $page1->entries[0]->id()->value());
        self::assertSame(self::AUDIT_ID_2, $page1->entries[1]->id()->value());

        // limit=2, offset=2 → remaining oldest entry only
        $page2 = $uc->execute(new ListForumModerationAuditForAdminInput($admin, 2, [], 2));
        self::assertCount(1, $page2->entries);
        self::assertSame(self::AUDIT_ID_1, $page2->entries[0]->id()->value());
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::registeredInTenant(self::REGISTERED, $tenant);

        $this->expectException(ForbiddenException::class);

        (new ListForumModerationAuditForAdmin(new InMemoryForumModerationAuditRepository()))
            ->execute(new ListForumModerationAuditForAdminInput($member));
    }
}
