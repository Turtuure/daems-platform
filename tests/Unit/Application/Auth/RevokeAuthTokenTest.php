<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\RevokeAuthToken\RevokeAuthToken;
use Daems\Application\Auth\RevokeAuthToken\RevokeAuthTokenInput;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RevokeAuthTokenTest extends TestCase
{
    public function testSetsRevokedAt(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $id = AuthTokenId::generate();
        $repo->store(new AuthToken(
            $id,
            hash('sha256', 's'),
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        ));

        (new RevokeAuthToken($repo, FrozenClock::at('2026-04-20T12:00:00Z')))
            ->execute(new RevokeAuthTokenInput($id));

        $updated = $repo->findByHash(hash('sha256', 's'));
        $this->assertNotNull($updated);
        $this->assertNotNull($updated->revokedAt());
    }
}
