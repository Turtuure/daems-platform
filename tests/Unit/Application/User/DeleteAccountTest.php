<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\DeleteAccount\DeleteAccount;
use Daems\Application\User\DeleteAccount\DeleteAccountInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class DeleteAccountTest extends TestCase
{
    // TEMP: PR 2 Task 17/18 will supply real tenant context.
    private function acting(UserId $id, string $role = 'registered'): ActingUser
    {
        $tenantRole = UserTenantRole::tryFrom($role) ?? UserTenantRole::Registered;
        return new ActingUser(
            id:                 $id,
            email:              'test@daems.fi',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            roleInActiveTenant: $tenantRole,
        );
    }

    private function seed(InMemoryUserRepository $repo, string $role = 'registered'): User
    {
        $u = new User(
            UserId::generate(),
            'N',
            'n' . uniqid() . '@x.com',
            password_hash('p', PASSWORD_BCRYPT),
            '1990-01-01',
            $role,
        );
        $repo->save($u);
        return $u;
    }

    public function testSelfDeleteSucceeds(): void
    {
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $acting = $this->acting($victim->id());

        $out = (new DeleteAccount($repo))->execute(new DeleteAccountInput($acting, $victim->id()->value()));

        $this->assertTrue($out->deleted);
        $this->assertNull($repo->findById($victim->id()->value()));
    }

    public function testDeletingOtherUserThrowsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $attacker = $this->acting(UserId::generate());

        (new DeleteAccount($repo))->execute(new DeleteAccountInput($attacker, $victim->id()->value()));
    }

    public function testAdminCanDeleteAnyone(): void
    {
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $admin = $this->acting(UserId::generate(), 'admin');

        $out = (new DeleteAccount($repo))->execute(new DeleteAccountInput($admin, $victim->id()->value()));

        $this->assertTrue($out->deleted);
    }

    public function testTargetUserNotFoundReturnsErrorOutput(): void
    {
        $repo = new InMemoryUserRepository();
        $acting = $this->acting(UserId::generate(), 'admin');

        $out = (new DeleteAccount($repo))->execute(new DeleteAccountInput($acting, UserId::generate()->value()));

        $this->assertFalse($out->deleted);
        $this->assertNotNull($out->error);
    }
}
