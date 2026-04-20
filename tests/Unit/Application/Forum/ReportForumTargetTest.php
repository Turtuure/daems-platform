<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Forum;

use Daems\Application\Forum\ReportForumTarget\ReportForumTarget;
use Daems\Application\Forum\ReportForumTarget\ReportForumTargetInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryAdminApplicationDismissalRepository;
use Daems\Tests\Support\Fake\InMemoryForumReportRepository;
use Daems\Tests\Support\Fake\InMemoryForumRepository;
use Daems\Tests\Support\ForumSeed;
use PHPUnit\Framework\TestCase;

final class ReportForumTargetTest extends TestCase
{
    private const TENANT_ID  = '11111111-1111-7111-8111-111111111111';
    private const USER_ID    = '01958000-0000-7000-8000-000000000a01';
    private const ADMIN_ID   = '01958000-0000-7000-8000-000000000b01';
    private const POST_ID    = '01958000-0000-7000-8000-0000000a0001';

    private TenantId $tenant;
    private ActingUser $user;
    private InMemoryForumRepository $forum;
    private InMemoryForumReportRepository $reports;
    private InMemoryAdminApplicationDismissalRepository $dismissals;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString(self::TENANT_ID);
        $this->user   = ActingUserFactory::registeredInTenant(self::USER_ID, $this->tenant);
        $this->forum  = new InMemoryForumRepository();
        $this->reports = new InMemoryForumReportRepository();
        $this->dismissals = new InMemoryAdminApplicationDismissalRepository();
    }

    public function test_reports_a_post_and_upserts_once(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, self::POST_ID);
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);

        $out = $uc->execute(new ReportForumTargetInput(
            $this->user,
            'post',
            self::POST_ID,
            'spam',
            'scam link',
        ));

        self::assertTrue($out->success);
        self::assertCount(1, $this->reports->reports);
        $r = array_values($this->reports->reports)[0];
        self::assertSame(ForumReport::TARGET_POST, $r->targetType());
        self::assertSame('spam', $r->reasonCategory());
        self::assertSame(self::POST_ID, $r->targetId());
        self::assertSame(self::USER_ID, $r->reporterUserId());
        self::assertSame('scam link', $r->reasonDetail());
    }

    public function test_same_reporter_same_target_upserts_updated_reason(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, self::POST_ID);
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);

        $uc->execute(new ReportForumTargetInput($this->user, 'post', self::POST_ID, 'spam', null));
        $uc->execute(new ReportForumTargetInput($this->user, 'post', self::POST_ID, 'harassment', 'new reason'));

        self::assertCount(1, $this->reports->reports);
        $r = array_values($this->reports->reports)[0];
        self::assertSame('harassment', $r->reasonCategory());
        self::assertSame('new reason', $r->reasonDetail());
    }

    public function test_unknown_target_throws_not_found(): void
    {
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);
        $this->expectException(NotFoundException::class);
        $uc->execute(new ReportForumTargetInput(
            $this->user,
            'post',
            '01958000-0000-7000-8000-0000000aFFFF',
            'spam',
            null,
        ));
    }

    public function test_invalid_reason_category_rejected(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, self::POST_ID);
        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);
        $this->expectException(\InvalidArgumentException::class);
        $uc->execute(new ReportForumTargetInput($this->user, 'post', self::POST_ID, 'not_a_reason', null));
    }

    public function test_dismissal_cleared_on_new_report(): void
    {
        ForumSeed::seedPost($this->forum, $this->tenant, self::POST_ID);
        $this->dismissals->dismiss($this->tenant, self::ADMIN_ID, 'forum_report', 'post:' . self::POST_ID);

        $uc = new ReportForumTarget($this->forum, $this->reports, $this->dismissals);
        $uc->execute(new ReportForumTargetInput($this->user, 'post', self::POST_ID, 'spam', null));

        self::assertFalse($this->dismissals->isDismissed(
            $this->tenant,
            self::ADMIN_ID,
            'forum_report',
            'post:' . self::POST_ID,
        ));
    }
}
