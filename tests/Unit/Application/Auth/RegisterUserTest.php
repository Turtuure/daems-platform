<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\Auth\RegisterUser\RegisterUserInput;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class RegisterUserTest extends TestCase
{
    private function makeInput(array $overrides = []): RegisterUserInput
    {
        return new RegisterUserInput(
            $overrides['name']        ?? 'John Doe',
            $overrides['email']       ?? 'john@example.com',
            $overrides['password']    ?? 'SecurePass1!',
            $overrides['dateOfBirth'] ?? '1990-05-20',
        );
    }

    public function testReturnsIdOnSuccessfulRegistration(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);
        $repo->expects($this->once())->method('save');

        $out = (new RegisterUser($repo))->execute($this->makeInput());

        $this->assertNotNull($out->id);
        $this->assertNull($out->error);
    }

    public function testReturnsErrorWhenEmailAlreadyExists(): void
    {
        $existingUser = new User(
            UserId::generate(),
            'Jane Doe',
            'john@example.com',
            password_hash('old-pass', PASSWORD_BCRYPT),
            '1985-01-01',
        );

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn($existingUser);
        $repo->expects($this->never())->method('save');

        $out = (new RegisterUser($repo))->execute($this->makeInput());

        $this->assertNull($out->id);
        $this->assertNotNull($out->error);
    }

    public function testSavedUserHasHashedPassword(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);

        $capturedUser = null;
        $repo->method('save')->willReturnCallback(function (User $u) use (&$capturedUser): void {
            $capturedUser = $u;
        });

        (new RegisterUser($repo))->execute($this->makeInput(['password' => 'PlainPass99!']));

        $this->assertNotNull($capturedUser);
        $this->assertTrue(password_verify('PlainPass99!', $capturedUser->passwordHash()));
        $this->assertNotSame('PlainPass99!', $capturedUser->passwordHash());
    }

    public function testReturnedIdIsValidUuid7Format(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findByEmail')->willReturn(null);
        $repo->method('save');

        $out = (new RegisterUser($repo))->execute($this->makeInput());

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $out->id,
        );
    }
}
