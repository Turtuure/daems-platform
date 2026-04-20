<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage\Forum;

use Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUser;
use Daems\Application\Backstage\Forum\WarnForumUser\WarnForumUserInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryForumUserWarningRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WarnForumUserTest extends TestCase
{
    private const TENANT_ID = '11111111-1111-7111-8111-111111111111';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000a01';
    private const USER_ID   = '01958000-0000-7000-8000-0000000b0001';

    public function test_writes_warning_for_user(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $warnings = new InMemoryForumUserWarningRepository();

        $uc = new WarnForumUser($warnings);
        $uc->execute(new WarnForumUserInput($admin, self::USER_ID, 'Please follow the rules.'));

        $warned = $warnings->listForUserForTenant(self::USER_ID, $tenant);
        self::assertCount(1, $warned);
        self::assertSame(self::USER_ID, $warned[0]->userId());
        self::assertSame('Please follow the rules.', $warned[0]->reason());
        self::assertNull($warned[0]->relatedReportId());
        self::assertSame(self::ADMIN_ID, $warned[0]->issuedBy());
        self::assertTrue($warned[0]->tenantId()->equals($tenant));
    }

    public function test_empty_reason_rejected(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $uc = new WarnForumUser(new InMemoryForumUserWarningRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reason_required');
        $uc->execute(new WarnForumUserInput($admin, self::USER_ID, '   '));
    }

    public function test_non_admin_forbidden(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $member = ActingUserFactory::memberInTenant(self::ADMIN_ID, $tenant);

        $uc = new WarnForumUser(new InMemoryForumUserWarningRepository());

        $this->expectException(ForbiddenException::class);
        $uc->execute(new WarnForumUserInput($member, self::USER_ID, 'something'));
    }

    public function test_reason_capped_at_500_chars(): void
    {
        $tenant = TenantId::fromString(self::TENANT_ID);
        $admin  = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenant);

        $warnings = new InMemoryForumUserWarningRepository();

        $longReason = str_repeat('a', 600);

        $uc = new WarnForumUser($warnings);
        $uc->execute(new WarnForumUserInput($admin, self::USER_ID, $longReason));

        $warned = $warnings->listForUserForTenant(self::USER_ID, $tenant);
        self::assertCount(1, $warned);
        self::assertSame(500, strlen($warned[0]->reason()));
    }
}
