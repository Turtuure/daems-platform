<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccountInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\ImmediateTransactionManager;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryMemberStatusAuditRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class AnonymiseAccountTest extends TestCase
{
    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function acting(UserId $id, string $role = 'registered', bool $isPlatformAdmin = false): ActingUser
    {
        $tenantRole = UserTenantRole::tryFrom($role) ?? UserTenantRole::Registered;
        return new ActingUser(
            id:                 $id,
            email:              'test@daems.fi',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId,
            roleInActiveTenant: $tenantRole,
        );
    }

    private function seedUser(InMemoryUserRepository $repo, ?\DateTimeImmutable $deletedAt = null): User
    {
        $u = new User(
            id:           UserId::generate(),
            name:         'Testi Käyttäjä',
            email:        'u' . uniqid() . '@x.com',
            passwordHash: password_hash('p', PASSWORD_BCRYPT),
            dateOfBirth:  '1990-01-01',
            deletedAt:    $deletedAt,
        );
        $repo->save($u);
        return $u;
    }

    private function makeUseCase(
        InMemoryUserRepository $users,
        InMemoryUserTenantRepository $userTenants,
        InMemoryAuthTokenRepository $tokens,
        InMemoryMemberStatusAuditRepository $audit,
    ): AnonymiseAccount {
        return new AnonymiseAccount(
            $users,
            $userTenants,
            $tokens,
            $audit,
            new ImmediateTransactionManager(),
            FrozenClock::at('2026-04-20T10:00:00Z'),
            new class implements \Daems\Domain\Shared\IdGeneratorInterface {
                public function generate(): string
                {
                    return \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value();
                }
            },
        );
    }

    public function testSelfCanAnonymise(): void
    {
        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $target = $this->seedUser($users);
        $acting = $this->acting($target->id());

        $out = $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput($target->id()->value(), $acting));

        $this->assertTrue($out->success);
        $stored = $users->findById($target->id()->value());
        $this->assertNotNull($stored);
        $this->assertSame('Anonyymi', $stored?->name());
        $this->assertNull($stored?->passwordHash());
        $this->assertNotNull($stored?->deletedAt());
    }

    public function testAdminCanAnonymiseOtherUser(): void
    {
        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $target = $this->seedUser($users);
        $adminId = UserId::generate();
        $acting = $this->acting($adminId, 'admin');

        $out = $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput($target->id()->value(), $acting));

        $this->assertTrue($out->success);
        $stored = $users->findById($target->id()->value());
        $this->assertSame('Anonyymi', $stored?->name());
    }

    public function testNonAdminNonSelfThrowsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $target = $this->seedUser($users);
        $attacker = $this->acting(UserId::generate(), 'member');

        $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput($target->id()->value(), $attacker));
    }

    public function testPlatformAdminCanAnonymise(): void
    {
        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $target = $this->seedUser($users);
        $gsa = new ActingUser(
            id:                 UserId::generate(),
            email:              'gsa@daems.fi',
            isPlatformAdmin:    true,
            activeTenant:       $this->tenantId,
            roleInActiveTenant: null,
        );

        $out = $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput($target->id()->value(), $gsa));

        $this->assertTrue($out->success);
    }

    public function testNotFoundUserThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('user_not_found');

        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $acting = $this->acting(UserId::generate(), 'admin');

        $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput(UserId::generate()->value(), $acting));
    }

    public function testAlreadyAnonymisedThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $target = $this->seedUser($users, new \DateTimeImmutable('2026-04-01 00:00:00'));
        $acting = $this->acting($target->id());

        $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput($target->id()->value(), $acting));
    }

    public function testSideEffects(): void
    {
        $users = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $tokens = new InMemoryAuthTokenRepository();
        $audit = new InMemoryMemberStatusAuditRepository();

        $target = $this->seedUser($users);

        // Attach a tenant role
        $userTenants->attach($target->id(), $this->tenantId, UserTenantRole::Member);

        // Give the user a token
        $tokenHash = hash('sha256', 'sometoken');
        $now = new \DateTimeImmutable('2026-04-20T10:00:00Z');
        $tokens->store(\Daems\Domain\Auth\AuthToken::fromPersistence(
            \Daems\Domain\Auth\AuthTokenId::generate(),
            $tokenHash,
            $target->id(),
            $now,
            $now,
            $now->modify('+7 days'),
            null,
            null,
            null,
        ));

        $acting = $this->acting($target->id());
        $this->makeUseCase($users, $userTenants, $tokens, $audit)
            ->execute(new AnonymiseAccountInput($target->id()->value(), $acting));

        // User fields wiped
        $stored = $users->findById($target->id()->value());
        $this->assertSame('Anonyymi', $stored?->name());
        $this->assertNull($stored?->passwordHash());
        $this->assertSame('', $stored?->country());
        $this->assertSame('terminated', $stored?->membershipStatus());
        $this->assertNotNull($stored?->deletedAt());

        // Token removed
        $this->assertNull($tokens->findByHash($tokenHash));

        // Tenant role removed
        $this->assertNull($userTenants->findRole($target->id(), $this->tenantId));

        // Audit row recorded
        $rows = $audit->allForTenant($this->tenantId->value());
        $this->assertCount(1, $rows);
        $this->assertSame('terminated', $rows[0]->newStatus);
        $this->assertSame('user_anonymised', $rows[0]->reason);
    }
}
