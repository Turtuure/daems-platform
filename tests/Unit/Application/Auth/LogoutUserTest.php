<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Application\Auth\LogoutUser\LogoutUserInput;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LogoutUserTest extends TestCase
{
    public function testRevokesTokenByHash(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $repo->store(AuthToken::fromPersistence(
            AuthTokenId::generate(),
            hash('sha256', 'raw-secret'),
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        ));

        (new LogoutUser($repo, FrozenClock::at('2026-04-20T00:00:00Z')))
            ->execute(new LogoutUserInput('raw-secret'));

        $this->assertNotNull($repo->findByHash(hash('sha256', 'raw-secret'))->revokedAt());
    }

    public function testIdempotentOnUnknownToken(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        (new LogoutUser($repo, FrozenClock::at('2026-04-20T00:00:00Z')))
            ->execute(new LogoutUserInput('not-a-token'));
        $this->assertEmpty($repo->byHash);
    }
}
