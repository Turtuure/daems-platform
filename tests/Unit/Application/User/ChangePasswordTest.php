<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\ChangePassword\ChangePasswordInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ChangePasswordTest extends TestCase
{
    private function makeUser(string $plainPassword, ?UserId $id = null): User
    {
        return new User(
            $id ?? UserId::generate(),
            'Jane Doe',
            'jane@example.com',
            password_hash($plainPassword, PASSWORD_BCRYPT),
            '1990-01-01',
        );
    }

    public function testSucceedsWithValidCurrentPasswordAndNewPassword(): void
    {
        $id = UserId::generate();
        $user = $this->makeUser('OldPass1!', $id);

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects($this->once())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput(new ActingUser($id, 'registered'), $id->value(), 'OldPass1!', 'NewPass2@'),
        );

        $this->assertNull($out->error);
    }

    public function testReturnsErrorWhenNewPasswordIsTooShort(): void
    {
        $id = UserId::generate();
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->expects($this->never())->method('findById');
        $repo->expects($this->never())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput(new ActingUser($id, 'registered'), $id->value(), 'OldPass1!', 'short'),
        );

        $this->assertNotNull($out->error);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $id = UserId::generate();
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects($this->never())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput(new ActingUser($id, 'registered'), $id->value(), 'OldPass1!', 'NewPass2@'),
        );

        $this->assertNotNull($out->error);
    }

    public function testReturnsErrorWhenCurrentPasswordIsWrong(): void
    {
        $id = UserId::generate();
        $user = $this->makeUser('RealPass1!', $id);

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects($this->never())->method('updatePassword');

        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput(new ActingUser($id, 'registered'), $id->value(), 'WrongPass!', 'NewPass2@'),
        );

        $this->assertNotNull($out->error);
    }

    public function testUpdatedHashVerifiesNewPassword(): void
    {
        $id = UserId::generate();
        $user    = $this->makeUser('OldPass1!', $id);
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
            new ChangePasswordInput(new ActingUser($id, 'registered'), $id->value(), 'OldPass1!', $newPass),
        );

        $this->assertNotNull($capturedHash);
        $this->assertTrue(password_verify($newPass, $capturedHash));
    }

    public function testForbiddenWhenOtherUserTriesToChange(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(UserRepositoryInterface::class);

        (new ChangePassword($repo))->execute(
            new ChangePasswordInput(
                new ActingUser(UserId::generate(), 'registered'),
                UserId::generate()->value(),
                'OldPass1!',
                'NewPass2@',
            ),
        );
    }

    /**
     * The authorization check must fire BEFORE length/lookup/verify checks.
     * An attacker probing another user's account must never observe
     * differences in response for short-password vs correct-length
     * vs wrong-current-password — all three must collapse to 403.
     */
    public function testForbiddenFiresBeforeLengthAndLookupChecks(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->expects($this->never())->method('findById');
        $repo->expects($this->never())->method('updatePassword');

        // Short new password would otherwise return an error output,
        // but Forbidden must short-circuit first.
        try {
            (new ChangePassword($repo))->execute(new ChangePasswordInput(
                new ActingUser(UserId::generate(), 'registered'),
                UserId::generate()->value(),
                'OldPass1!',
                'x',
            ));
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }

        // 73-byte new password would otherwise return an error output,
        // but Forbidden must short-circuit first.
        try {
            (new ChangePassword($repo))->execute(new ChangePasswordInput(
                new ActingUser(UserId::generate(), 'registered'),
                UserId::generate()->value(),
                'OldPass1!',
                str_repeat('a', 73),
            ));
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAdminAlsoForbiddenForOtherUser(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(UserRepositoryInterface::class);

        (new ChangePassword($repo))->execute(
            new ChangePasswordInput(
                new ActingUser(UserId::generate(), 'admin'),
                UserId::generate()->value(),
                'OldPass1!',
                'NewPass2@',
            ),
        );
    }

    public function testRejects73BytePassword(): void
    {
        $id = UserId::generate();
        $repo = $this->createMock(UserRepositoryInterface::class);
        $out = (new ChangePassword($repo))->execute(
            new ChangePasswordInput(new ActingUser($id, 'registered'), $id->value(), 'x', str_repeat('a', 73)),
        );
        $this->assertNotNull($out->error);
    }
}
