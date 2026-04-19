<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthTokenInput;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CreateAuthTokenTest extends TestCase
{
    public function testStoresHashNotRawToken(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');
        $userId = UserId::generate();

        $out = (new CreateAuthToken($repo, $clock))
            ->execute(new CreateAuthTokenInput($userId, 'ua', '1.1.1.1'));

        $raw = $out->rawToken;
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $raw);
        foreach ($repo->byHash as $hash => $_) {
            $this->assertSame(hash('sha256', $raw), $hash);
        }
    }

    public function testExpiresAtSevenDaysFromIssue(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');

        $out = (new CreateAuthToken($repo, $clock))
            ->execute(new CreateAuthTokenInput(UserId::generate(), null, null));

        $this->assertSame('2026-04-26T00:00:00+00:00', $out->expiresAt->format('c'));
    }

    public function testGeneratesDistinctTokens(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');
        $uc = new CreateAuthToken($repo, $clock);
        $a = $uc->execute(new CreateAuthTokenInput(UserId::generate(), null, null));
        $b = $uc->execute(new CreateAuthTokenInput(UserId::generate(), null, null));
        $this->assertNotSame($a->rawToken, $b->rawToken);
    }

    public function testStoresUserAgentAndIp(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');
        (new CreateAuthToken($repo, $clock))
            ->execute(new CreateAuthTokenInput(UserId::generate(), 'ua/1', '9.9.9.9'));

        $stored = array_values($repo->byHash)[0];
        $this->assertSame('ua/1', $stored->userAgent());
        $this->assertSame('9.9.9.9', $stored->ip());
    }
}
