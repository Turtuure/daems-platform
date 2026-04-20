<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEdit;
use Daems\Application\Backstage\Forum\ResolveForumReportByEdit\ResolveForumReportByEditInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResolveForumReportByEditTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000a01';
    private const MEMBER_ID  = '01958000-0000-7000-8000-000000000a02';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';
    private const TOPIC_ID   = '01958000-0000-7000-8000-000000010003';
    private const REPORTER_1 = '01958000-0000-7000-8000-000000000c01';
    private const REPORTER_2 = '01958000-0000-7000-8000-000000000c02';
    private const MISSING_ID = '01958000-0000-7000-8000-00000000dead';

    public function test_overwrites_post_and_resolves_and_audits(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum   = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'original');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_1, 'spam');
        $reports->seedOpen($tenant, 'post', self::POST_ID, self::REPORTER_2, 'harassment');

        $uc = new ResolveForumReportByEdit($forum, $reports, $audit);
        $uc->execute(new ResolveForumReportByEditInput(
            $admin,
            'post',
            self::POST_ID,
            'clean',
            'Cleaned up profanity.',
        ));

        // 1) Post content has been overwritten.
        $post = $forum->findPostByIdForTenant(self::POST_ID, $tenant);
        self::assertNotNull($post);
        self::assertSame('clean', $post->content());

        // 2) All open reports for the target are resolved with action=edited.
        self::assertCount(2, $reports->reports);
        foreach ($reports->reports as $r) {
            self::assertSame(ForumReport::STATUS_RESOLVED, $r->status());
            self::assertSame('edited', $r->resolutionAction());
            self::assertSame(self::ADMIN_ID, $r->resolvedBy());
            self::assertSame('Cleaned up profanity.', $r->resolutionNote());
        }

        // 3) Audit row: original_payload.content = 'original', new_payload.content = 'clean'.
        self::assertCount(1, $audit->rows);
        $row = $audit->rows[0];
        self::assertSame('edited', $row->action());
        self::assertSame('post', $row->targetType());
        self::assertSame(self::POST_ID, $row->targetId());
        self::assertSame(self::ADMIN_ID, $row->performedBy());
        self::assertSame('Cleaned up profanity.', $row->reason());

        $original = $row->originalPayload();
        self::assertNotNull($original);
        self::assertSame('original', $original['content'] ?? null);

        $new = $row->newPayload();
        self::assertNotNull($new);
        self::assertSame('clean', $new['content'] ?? null);
        self::assertArrayHasKey('edited_at', $new);
        self::assertIsString($new['edited_at']);
    }

    public function test_topic_target_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new ResolveForumReportByEdit(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot_edit_topic');

        $uc->execute(new ResolveForumReportByEditInput(
            $admin,
            'topic',
            self::TOPIC_ID,
            'rewritten',
            null,
        ));
    }

    public function test_empty_content_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $forum   = new InMemoryForumRepository();
        $reports = new InMemoryForumReportRepository();
        $audit   = new InMemoryForumModerationAuditRepository();

        ForumSeed::seedPost($forum, $tenant, self::POST_ID, null, 'original');

        $uc = new ResolveForumReportByEdit($forum, $reports, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('content_required');

        $uc->execute(new ResolveForumReportByEditInput(
            $admin,
            'post',
            self::POST_ID,
            "   \t\n  ",
            null,
        ));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::MEMBER_ID, $tenant);

        $uc = new ResolveForumReportByEdit(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('not_admin');

        $uc->execute(new ResolveForumReportByEditInput(
            $member,
            'post',
            self::POST_ID,
            'clean',
            null,
        ));
    }

    public function test_unknown_post_throws(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new ResolveForumReportByEdit(
            new InMemoryForumRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumModerationAuditRepository(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('post_not_found');

        $uc->execute(new ResolveForumReportByEditInput(
            $admin,
            'post',
            self::MISSING_ID,
            'clean',
            null,
        ));
    }
}
