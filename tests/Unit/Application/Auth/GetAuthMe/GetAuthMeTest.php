<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth\GetAuthMe;

use Daems\Application\Auth\GetAuthMe\GetAuthMe;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GetAuthMeTest extends TestCase
{
    private TenantId $tenantId;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->tenant = new Tenant(
            $this->tenantId,
            TenantSlug::fromString('daems'),
            'Daems Society',
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
        );
    }

    private function makeUser(?UserId $id = null): User
    {
        return new User(
            $id ?? UserId::generate(),
            'Sam',
            'sam@daems.fi',
            password_hash('secret', PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    private function makeToken(UserId $userId, string $rawToken, string $expiresAt = '2026-04-26T10:00:00Z'): AuthToken
    {
        $issued = new DateTimeImmutable('2026-04-19T10:00:00Z');
        return AuthToken::fromPersistence(
            AuthTokenId::generate(),
            hash('sha256', $rawToken),
            $userId,
            $issued,
            $issued,
            new DateTimeImmutable($expiresAt),
            null,
            null,
            null,
        );
    }

    private function makeActor(
        UserId $userId,
        ?UserTenantRole $role = UserTenantRole::Admin,
    ): ActingUser {
        return new ActingUser(
            id:                 $userId,
            email:              'sam@daems.fi',
            isPlatformAdmin:    false,
            activeTenant:       $this->tenantId,
            roleInActiveTenant: $role,
        );
    }

    private function makeUseCase(
        InMemoryUserRepository $users,
        InMemoryTenantRepository $tenants,
        InMemoryAuthTokenRepository $tokens,
    ): GetAuthMe {
        return new GetAuthMe($users, $tenants, $tokens);
    }

    public function testHappyPathReturnsFullOutput(): void
    {
        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $user = $this->makeUser($userId);
        $users->save($user);

        $tenants = new InMemoryTenantRepository();
        $tenants->save($this->tenant);

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store($this->makeToken($userId, 'my-raw-token', '2026-04-26T10:00:00Z'));

        $actor = $this->makeActor($userId, UserTenantRole::Admin);
        $output = $this->makeUseCase($users, $tenants, $tokens)->execute($actor, 'my-raw-token');

        $this->assertSame($userId->value(), $output->userId);
        $this->assertSame('Sam', $output->name);
        $this->assertSame('sam@daems.fi', $output->email);
        $this->assertFalse($output->isPlatformAdmin);
        $this->assertSame('daems', $output->tenantSlug);
        $this->assertSame('Daems Society', $output->tenantName);
        $this->assertSame('admin', $output->roleInTenant);
        $this->assertNotNull($output->tokenExpiresAt);
        $this->assertStringStartsWith('2026-04-26T10:00:00', $output->tokenExpiresAt);
    }

    public function testToArrayShapeMatchesSpec(): void
    {
        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $users->save($this->makeUser($userId));

        $tenants = new InMemoryTenantRepository();
        $tenants->save($this->tenant);

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store($this->makeToken($userId, 'tok'));

        $actor = $this->makeActor($userId, UserTenantRole::Member);
        $output = $this->makeUseCase($users, $tenants, $tokens)->execute($actor, 'tok');
        $data = $output->toArray();

        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('tenant', $data);
        $this->assertArrayHasKey('role_in_tenant', $data);
        $this->assertArrayHasKey('token_expires_at', $data);

        $this->assertArrayHasKey('id', $data['user']);
        $this->assertArrayHasKey('name', $data['user']);
        $this->assertArrayHasKey('email', $data['user']);
        $this->assertArrayHasKey('is_platform_admin', $data['user']);

        $this->assertArrayHasKey('slug', $data['tenant']);
        $this->assertArrayHasKey('name', $data['tenant']);
    }

    public function testMissingUserThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ActingUser points to missing user/');

        $users = new InMemoryUserRepository(); // empty — user not saved
        $tenants = new InMemoryTenantRepository();
        $tenants->save($this->tenant);
        $tokens = new InMemoryAuthTokenRepository();

        $actor = $this->makeActor(UserId::generate());
        $this->makeUseCase($users, $tenants, $tokens)->execute($actor, 'any');
    }

    public function testMissingTenantThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ActingUser points to missing tenant/');

        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $users->save($this->makeUser($userId));

        $tenants = new InMemoryTenantRepository(); // empty — tenant not saved
        $tokens = new InMemoryAuthTokenRepository();

        $actor = $this->makeActor($userId);
        $this->makeUseCase($users, $tenants, $tokens)->execute($actor, 'any');
    }

    public function testNullRoleInTenantProducesNullInOutput(): void
    {
        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $users->save($this->makeUser($userId));

        $tenants = new InMemoryTenantRepository();
        $tenants->save($this->tenant);

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store($this->makeToken($userId, 'tok2'));

        $actor = $this->makeActor($userId, null); // no role
        $output = $this->makeUseCase($users, $tenants, $tokens)->execute($actor, 'tok2');

        $this->assertNull($output->roleInTenant);
        $this->assertNull($output->toArray()['role_in_tenant']);
    }

    public function testUnknownTokenProducesNullTokenExpiresAt(): void
    {
        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $users->save($this->makeUser($userId));

        $tenants = new InMemoryTenantRepository();
        $tenants->save($this->tenant);

        $tokens = new InMemoryAuthTokenRepository(); // no token stored

        $actor = $this->makeActor($userId);
        $output = $this->makeUseCase($users, $tenants, $tokens)->execute($actor, 'ghost-token');

        $this->assertNull($output->tokenExpiresAt);
        $this->assertNull($output->toArray()['token_expires_at']);
    }
}
