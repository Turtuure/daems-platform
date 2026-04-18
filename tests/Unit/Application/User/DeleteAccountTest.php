<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\DeleteAccount\DeleteAccount;
use Daems\Application\User\DeleteAccount\DeleteAccountInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class DeleteAccountTest extends TestCase
{
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
        $acting = new ActingUser($victim->id(), 'registered');

        $out = (new DeleteAccount($repo))->execute(new DeleteAccountInput($acting, $victim->id()->value()));

        $this->assertTrue($out->deleted);
        $this->assertNull($repo->findById($victim->id()->value()));
    }

    public function testDeletingOtherUserThrowsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $attacker = new ActingUser(UserId::generate(), 'registered');

        (new DeleteAccount($repo))->execute(new DeleteAccountInput($attacker, $victim->id()->value()));
    }

    public function testAdminCanDeleteAnyone(): void
    {
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $admin = new ActingUser(UserId::generate(), 'admin');

        $out = (new DeleteAccount($repo))->execute(new DeleteAccountInput($admin, $victim->id()->value()));

        $this->assertTrue($out->deleted);
    }

    public function testTargetUserNotFoundReturnsErrorOutput(): void
    {
        $repo = new InMemoryUserRepository();
        $acting = new ActingUser(UserId::generate(), 'admin');

        $out = (new DeleteAccount($repo))->execute(new DeleteAccountInput($acting, UserId::generate()->value()));

        $this->assertFalse($out->deleted);
        $this->assertNotNull($out->error);
    }
}
