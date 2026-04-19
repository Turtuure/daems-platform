<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private TenantId $tenantId;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->tenant   = new Tenant(
            $this->tenantId,
            TenantSlug::fromString('daems'),
            'Daems',
            new DateTimeImmutable('2026-01-01'),
        );
    }

    private function harness(
        InMemoryAuthTokenRepository $tokens,
        InMemoryUserRepository $users,
        FrozenClock $clock,
    ): AuthMiddleware {
        $tenants = $this->createMock(TenantRepositoryInterface::class);
        $userTenants = $this->createMock(UserTenantRepositoryInterface::class);
        $userTenants->method('findRole')->willReturn(null);
        return new AuthMiddleware(
            new AuthenticateToken($tokens, $users, $clock, new \Daems\Tests\Support\NullLogger()),
            $tenants,
            $userTenants,
        );
    }

    private function requestWithTenant(array $headers = []): Request
    {
        return Request::forTesting('GET', '/x', headers: $headers)
            ->withAttribute('tenant', $this->tenant);
    }

    public function testThrowsUnauthorizedWhenHeaderMissing(): void
    {
        $this->expectException(UnauthorizedException::class);
        $mw = $this->harness(
            new InMemoryAuthTokenRepository(),
            new InMemoryUserRepository(),
            FrozenClock::at('2026-04-20T00:00:00Z'),
        );
        $mw->process($this->requestWithTenant(), static fn(): Response => Response::json([]));
    }

    public function testThrowsUnauthorizedWhenTokenUnknown(): void
    {
        $this->expectException(UnauthorizedException::class);
        $mw = $this->harness(
            new InMemoryAuthTokenRepository(),
            new InMemoryUserRepository(),
            FrozenClock::at('2026-04-20T00:00:00Z'),
        );
        $mw->process(
            $this->requestWithTenant(['Authorization' => 'Bearer unknown']),
            static fn(): Response => Response::json([]),
        );
    }

    public function testAttachesActingUserAndCallsNextWhenValid(): void
    {
        $userId = UserId::generate();
        $user = new User($userId, 'Jane', 'j@x.com', password_hash('p', PASSWORD_BCRYPT), '1990-01-01');

        $users = new InMemoryUserRepository();
        $users->save($user);

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store(AuthToken::fromPersistence(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        ));

        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $mw = $this->harness($tokens, $users, $clock);

        $received = null;
        $resp = $mw->process(
            $this->requestWithTenant(['Authorization' => 'Bearer secret']),
            static function (Request $r) use (&$received): Response {
                $received = $r;
                return Response::json(['ok' => true]);
            },
        );

        $this->assertSame(200, $resp->status());
        $this->assertNotNull($received?->actingUser());
        $this->assertSame($userId->value(), $received->actingUser()->id->value());
        $this->assertTrue($received->actingUser()->activeTenant->equals($this->tenantId));
    }

    public function testThrowsUnauthorizedForRevokedToken(): void
    {
        $this->expectException(UnauthorizedException::class);
        $tokens = new InMemoryAuthTokenRepository();
        $userId = UserId::generate();
        $tokens->store(AuthToken::fromPersistence(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T12:00:00Z'),
            null,
            null,
        ));
        $mw = $this->harness($tokens, new InMemoryUserRepository(), FrozenClock::at('2026-04-20T00:00:00Z'));
        $mw->process(
            $this->requestWithTenant(['Authorization' => 'Bearer secret']),
            static fn(): Response => Response::json([]),
        );
    }
}
