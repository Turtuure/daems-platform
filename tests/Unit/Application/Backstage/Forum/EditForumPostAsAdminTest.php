<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdmin;
use Daems\Application\Backstage\Forum\EditForumPostAsAdmin\EditForumPostAsAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EditForumPostAsAdminTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID  = '01958000-0000-7000-8000-000000000a02';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const MISSING_ID = '01958000-0000-7000-8000-00000000dead';

    public function test_overwrites_post_content_and_audits(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'original');

        $uc = new EditForumPostAsAdmin($forum, $audit);
        $uc->execute(new EditForumPostAsAdminInput(
            $admin,
            self::POST_ID,
            'clean',
            'Fixed typos.',
        ));

        // 1) Post content has been overwritten.
        $post = $forum->findPostByIdForTenant(self::POST_ID, $tenant);
        self::assertNotNull($post);
        self::assertSame('clean', $post->content());

        // 2) Audit row: original_payload.content = 'original', new_payload.content = 'clean'.
        self::assertCount(1, $audit->rows);
        $row = $audit->rows[0];
        self::assertSame('edited', $row->action());
        self::assertSame('post', $row->targetType());
        self::assertSame(self::POST_ID, $row->targetId());
        self::assertSame(self::ADMIN_ID, $row->performedBy());
        self::assertSame('Fixed typos.', $row->reason());
        self::assertNull($row->relatedReportId());

        $original = $row->originalPayload();
        self::assertNotNull($original);
        self::assertSame('original', $original['content'] ?? null);

        $new = $row->newPayload();
        self::assertNotNull($new);
        self::assertSame('clean', $new['content'] ?? null);
        self::assertArrayHasKey('edited_at', $new);
        self::assertIsString($new['edited_at']);
    }

    public function test_empty_content_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'original');

        $uc = new EditForumPostAsAdmin($forum, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('content_required');

        $uc->execute(new EditForumPostAsAdminInput(
            $admin,
            self::POST_ID,
            "   \t\n  ",
            null,
        ));
    }

    public function test_unknown_post_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new EditForumPostAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('post_not_found');

        $uc->execute(new EditForumPostAsAdminInput(
            $admin,
            self::MISSING_ID,
            'clean',
            null,
        ));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $uc = new EditForumPostAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new EditForumPostAsAdminInput(
            $member,
            self::POST_ID,
            'clean',
            null,
        ));
    }
}
