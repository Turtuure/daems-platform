<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\ChangePassword\ChangePasswordInput;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ChangePasswordTest extends TestCase
{
    private function makeUser(string $plainPassword): User
    {
        return new User(
            UserId::generate(),
            'Jane Doe',
            'jane@example.com',
            password_hash($plainPassword, PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    public function testSucceedsWithValidCurrentPasswordAndNewPassword(): void
    {
        $user = $this->makeUser('OldPass1!');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects($this->once())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput($user->id()->value(), 'OldPass1!', 'NewPass2@'),
        );

        $this->assertNull($out->error);
    }

    public function testReturnsErrorWhenNewPasswordIsTooShort(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->expects($this->never())->method('findById');
        $repo->expects($this->never())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput('some-id', 'OldPass1!', 'short'),
        );

        $this->assertNotNull($out->error);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects($this->never())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput('nonexistent-id', 'OldPass1!', 'NewPass2@'),
        );

        $this->assertNotNull($out->error);
    }

    public function testReturnsErrorWhenCurrentPasswordIsWrong(): void
    {
        $user = $this->makeUser('RealPass1!');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects($this->never())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput($user->id()->value(), 'WrongPass!', 'NewPass2@'),
        );

        $this->assertNotNull($out->error);
    }

    public function testUpdatedHashVerifiesNewPassword(): void
    {
        $user    = $this->makeUser('OldPass1!');
        $newPass = 'BrandNew99!';

        $capturedHash = null;
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->method('updatePassword')->willReturnCallback(
            function (string $id, string $hash) use (&$capturedHash): void {
                $capturedHash = $hash;
            },
        );

        (new ChangePassword($repo))->execute(
            new ChangePasswordInput($user->id()->value(), 'OldPass1!', $newPass),
        );

        $this->assertNotNull($capturedHash);
        $this->assertTrue(password_verify($newPass, $capturedHash));
    }
}
