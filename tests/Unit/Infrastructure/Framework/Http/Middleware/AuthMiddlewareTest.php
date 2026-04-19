<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http\Middleware;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantRepositoryInterface;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\NullLogger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private UserId $userId;
    private TenantId $tenantId;
    private Tenant $tenant;

    /** Raw token value used in requests */
    private const TOKEN = 'test-bearer-token';

    protected function setUp(): void
    {
        $this->userId   = UserId::generate();
        $this->tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->tenant   = new Tenant(
            $this->tenantId,
            TenantSlug::fromString('daems'),
            'Daems',
            new DateTimeImmutable('2026-01-01'),
        );
    }

    /**
     * Build a real AuthenticateToken backed by in-memory repos.
     * Seeding $tokenRepo and $userRepo before passing is the caller's responsibility.
     */
    private function buildAuthenticateToken(
        InMemoryAuthTokenRepository $tokenRepo,
        InMemoryUserRepository $userRepo,
    ): AuthenticateToken {
        return new AuthenticateToken(
            $tokenRepo,
            $userRepo,
            FrozenClock::at('2026-04-20T00:00:00Z'),
            new NullLogger(),
        );
    }

    /**
     * Build a token+user pair seeded into the given repositories.
     * Returns the user (with the given isPlatformAdmin flag).
     */
    private function seedValidToken(
        InMemoryAuthTokenRepository $tokenRepo,
        InMemoryUserRepository $userRepo,
        bool $isPlatformAdmin = false,
    ): User {
        $user = new User(
            $this->userId,
            'Test User',
            'user@daems.fi',
            password_hash('pw', PASSWORD_BCRYPT),
            '1990-01-01',
            isPlatformAdmin: $isPlatformAdmin,
        );
        $userRepo->save($user);

        $tokenRepo->store(AuthToken::fromPersistence(
            AuthTokenId::generate(),
            hash('sha256', self::TOKEN),
            $this->userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null,
            null,
            null,
        ));

        return $user;
    }

    /** A request that already has the tenant attribute (as TenantContextMiddleware would set). */
    private function requestWithTenant(array $extraHeaders = []): Request
    {
        return Request::forTesting('GET', '/x', headers: $extraHeaders)
            ->withAttribute('tenant', $this->tenant);
    }

    private function noopTenants(): TenantRepositoryInterface
    {
        return $this->createMock(TenantRepositoryInterface::class);
    }

    private function noopUserTenants(?UserTenantRole $role = null): UserTenantRepositoryInterface
    {
        $stub = $this->createMock(UserTenantRepositoryInterface::class);
        $stub->method('findRole')->willReturn($role);
        return $stub;
    }

    // --- Happy path ---

    public function testHappyPathAttachesActingUserWithRole(): void
    {
        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $this->noopTenants(),
            $this->noopUserTenants(UserTenantRole::Member),
        );

        $captured = null;
        $resp = $mw->process(
            $this->requestWithTenant(['Authorization' => 'Bearer ' . self::TOKEN]),
            static function (Request $r) use (&$captured): Response {
                $captured = $r;
                return Response::json(['ok' => true]);
            },
        );

        $this->assertSame(200, $resp->status());
        $au = $captured?->actingUser();
        $this->assertNotNull($au);
        $this->assertSame($this->userId->value(), $au->id->value());
        $this->assertSame('user@daems.fi', $au->email);
        $this->assertFalse($au->isPlatformAdmin);
        $this->assertTrue($au->activeTenant->equals($this->tenantId));
        $this->assertSame(UserTenantRole::Member, $au->roleInActiveTenant);
    }

    public function testUserNotMemberOfTenantPassesThroughWithNullRole(): void
    {
        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $this->noopTenants(),
            $this->noopUserTenants(null),
        );

        $captured = null;
        $mw->process(
            $this->requestWithTenant(['Authorization' => 'Bearer ' . self::TOKEN]),
            static function (Request $r) use (&$captured): Response {
                $captured = $r;
                return Response::json([]);
            },
        );

        $this->assertNull($captured?->actingUser()?->roleInActiveTenant);
    }

    // --- Token / auth failures ---

    public function testMissingBearerTokenThrowsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken(new InMemoryAuthTokenRepository(), new InMemoryUserRepository()),
            $this->noopTenants(),
            $this->noopUserTenants(),
        );
        $mw->process($this->requestWithTenant(), fn (Request $r): Response => Response::json([]));
    }

    public function testInvalidTokenThrowsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken(new InMemoryAuthTokenRepository(), new InMemoryUserRepository()),
            $this->noopTenants(),
            $this->noopUserTenants(),
        );
        $mw->process(
            $this->requestWithTenant(['Authorization' => 'Bearer bad-token']),
            fn (Request $r): Response => Response::json([]),
        );
    }

    // --- Missing tenant attribute (TenantContextMiddleware not run) ---

    public function testMissingTenantAttributeThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TenantContextMiddleware must run before AuthMiddleware');

        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $this->noopTenants(),
            $this->noopUserTenants(),
        );
        $mw->process(
            Request::forTesting('GET', '/x', headers: ['Authorization' => 'Bearer ' . self::TOKEN]),
            fn (Request $r): Response => Response::json([]),
        );
    }

    // --- X-Daems-Tenant override: platform admin ---

    public function testPlatformAdminCanOverrideTenant(): void
    {
        $otherTenantId = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        $otherTenant = new Tenant(
            $otherTenantId,
            TenantSlug::fromString('other'),
            'Other',
            new DateTimeImmutable('2026-01-01'),
        );

        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo, isPlatformAdmin: true);

        $tenants = $this->createMock(TenantRepositoryInterface::class);
        $tenants->method('findBySlug')->with('other')->willReturn($otherTenant);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $tenants,
            $this->noopUserTenants(UserTenantRole::Admin),
        );

        $captured = null;
        $mw->process(
            $this->requestWithTenant([
                'Authorization'  => 'Bearer ' . self::TOKEN,
                'X-Daems-Tenant' => 'other',
            ]),
            static function (Request $r) use (&$captured): Response {
                $captured = $r;
                return Response::json([]);
            },
        );

        $au = $captured?->actingUser();
        $this->assertNotNull($au);
        $this->assertTrue($au->isPlatformAdmin);
        $this->assertTrue($au->activeTenant->equals($otherTenantId));
    }

    public function testNonPlatformAdminOverrideThrowsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('tenant_override_forbidden');

        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo, isPlatformAdmin: false);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $this->noopTenants(),
            $this->noopUserTenants(),
        );
        $mw->process(
            $this->requestWithTenant([
                'Authorization'  => 'Bearer ' . self::TOKEN,
                'X-Daems-Tenant' => 'other',
            ]),
            fn (Request $r): Response => Response::json([]),
        );
    }

    public function testOverrideWithUnknownSlugThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('unknown_tenant');

        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo, isPlatformAdmin: true);

        $tenants = $this->createMock(TenantRepositoryInterface::class);
        $tenants->method('findBySlug')->willReturn(null);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $tenants,
            $this->noopUserTenants(),
        );
        $mw->process(
            $this->requestWithTenant([
                'Authorization'  => 'Bearer ' . self::TOKEN,
                'X-Daems-Tenant' => 'nonexistent',
            ]),
            fn (Request $r): Response => Response::json([]),
        );
    }

    public function testOverrideTenantIsAlsoWrittenToRequestAttribute(): void
    {
        $otherTenantId = TenantId::fromString('01958000-0000-7000-8000-000000000002');
        $otherTenant = new Tenant(
            $otherTenantId,
            TenantSlug::fromString('other'),
            'Other',
            new DateTimeImmutable('2026-01-01'),
        );

        $tokenRepo = new InMemoryAuthTokenRepository();
        $userRepo  = new InMemoryUserRepository();
        $this->seedValidToken($tokenRepo, $userRepo, isPlatformAdmin: true);

        $tenants = $this->createMock(TenantRepositoryInterface::class);
        $tenants->method('findBySlug')->with('other')->willReturn($otherTenant);

        $mw = new AuthMiddleware(
            $this->buildAuthenticateToken($tokenRepo, $userRepo),
            $tenants,
            $this->noopUserTenants(),
        );

        $captured = null;
        $mw->process(
            $this->requestWithTenant([
                'Authorization'  => 'Bearer ' . self::TOKEN,
                'X-Daems-Tenant' => 'other',
            ]),
            static function (Request $r) use (&$captured): Response {
                $captured = $r;
                return Response::json([]);
            },
        );

        $this->assertSame($otherTenant, $captured?->attribute('tenant'));
    }
}
