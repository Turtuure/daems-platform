<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdmin;
use Daems\Application\Backstage\Forum\DeleteForumPostAsAdmin\DeleteForumPostAsAdminInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class DeleteForumPostAsAdminTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID  = '01958000-0000-7000-8000-000000000a02';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const MISSING_ID = '01958000-0000-7000-8000-00000000dead';

    public function test_deletes_post_and_writes_audit(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum = new InMemoryForumRepository();
        $audit = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'offensive content');

        $uc = new DeleteForumPostAsAdmin($forum, $audit);
        $uc->execute(new DeleteForumPostAsAdminInput($admin, self::POST_ID));

        // Post is gone
        self::assertNull($forum->findPostByIdForTenant(self::POST_ID, $tenant));

        // Audit row with original payload snapshot; no related report
        $entries = $audit->listRecentForTenant($tenant);
        self::assertCount(1, $entries);
        self::assertSame('deleted', $entries[0]->action());
        self::assertSame('post', $entries[0]->targetType());
        self::assertSame(self::POST_ID, $entries[0]->targetId());
        self::assertSame(self::ADMIN_ID, $entries[0]->performedBy());
        self::assertNull($entries[0]->relatedReportId());
        self::assertNull($entries[0]->reason());

        $payload = $entries[0]->originalPayload();
        self::assertNotNull($payload);
        self::assertSame('offensive content', $payload['content'] ?? null);
        self::assertArrayHasKey('topic_id', $payload);
        self::assertArrayHasKey('author_id', $payload);
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        (new DeleteForumPostAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        ))->execute(new DeleteForumPostAsAdminInput($member, self::POST_ID));
    }

    public function test_unknown_post_throws_not_found(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('post_not_found');

        (new DeleteForumPostAsAdmin(
            new InMemoryForumRepository(),
            new InMemoryForumModerationAuditRepository(),
        ))->execute(new DeleteForumPostAsAdminInput($admin, self::MISSING_ID));
    }
}
