<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Invite;

use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Application\Invite\IssueInvite\IssueInviteInput;
use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Invite\TokenGeneratorInterface;
use Daems\Tests\Support\Fake\InMemoryUserInviteRepository;
use PHPUnit\Framework\TestCase;

final class IssueInviteTest extends TestCase
{
    public function test_issues_invite_with_hashed_token_and_7_day_expiry(): void
    {
        $repo      = new InMemoryUserInviteRepository();
        $tokenGen  = new class implements TokenGeneratorInterface {
            public function generate(): string { return 'predictable-token'; }
        };
        $urls      = new class implements BaseUrlResolverInterface {
            public function resolveFrontendBaseUrl(string $tenantId): string
            { return 'https://frontend.test'; }
        };
        $clock     = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $ids       = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string { return 'invite-id-placeholder'; }
        };

        $sut = new IssueInvite($repo, $tokenGen, $urls, $clock, $ids);
        $out = $sut->execute(new IssueInviteInput('user-1', 'tenant-1'));

        self::assertSame('predictable-token', $out->rawToken);
        self::assertSame('https://frontend.test/invite/predictable-token', $out->inviteUrl);
        self::assertSame('2026-04-27 12:00:00', $out->expiresAt->format('Y-m-d H:i:s'));

        $stored = $repo->findByTokenHash(hash('sha256', 'predictable-token'));
        self::assertNotNull($stored);
        self::assertSame('user-1', $stored->userId);
        self::assertSame('tenant-1', $stored->tenantId);
        self::assertNull($stored->usedAt);
    }
}
