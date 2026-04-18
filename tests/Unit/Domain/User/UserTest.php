<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\User;

use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private function makeUser(array $overrides = []): User
    {
        $defaults = [
            'id'            => UserId::generate(),
            'name'          => 'Jane Doe',
            'email'         => 'jane@example.com',
            'passwordHash'  => password_hash('secret', PASSWORD_BCRYPT),
            'dateOfBirth'   => '1990-01-01',
        ];

        $merged = array_merge($defaults, $overrides);

        return new User(
            $merged['id'],
            $merged['name'],
            $merged['email'],
            $merged['passwordHash'],
            $merged['dateOfBirth'],
        );
    }

    public function testGettersReturnConstructorValues(): void
    {
        $id   = UserId::generate();
        $hash = password_hash('pass', PASSWORD_BCRYPT);

        $user = new User($id, 'Alice Smith', 'alice@example.com', $hash, '1985-06-15');

        $this->assertSame($id, $user->id());
        $this->assertSame('Alice Smith', $user->name());
        $this->assertSame('alice@example.com', $user->email());
        $this->assertSame($hash, $user->passwordHash());
        $this->assertSame('1985-06-15', $user->dateOfBirth());
    }

    public function testDefaultRoleIsRegistered(): void
    {
        $user = $this->makeUser();

        $this->assertSame('registered', $user->role());
    }

    public function testDefaultMembershipTypeIsIndividual(): void
    {
        $user = $this->makeUser();

        $this->assertSame('individual', $user->membershipType());
    }

    public function testDefaultMembershipStatusIsActive(): void
    {
        $user = $this->makeUser();

        $this->assertSame('active', $user->membershipStatus());
    }

    public function testMemberNumberIsNullByDefault(): void
    {
        $user = $this->makeUser();

        $this->assertNull($user->memberNumber());
    }

    public function testPasswordHashIsVerifiable(): void
    {
        $plain = 'MySecretPassword1!';
        $user  = $this->makeUser(['passwordHash' => password_hash($plain, PASSWORD_BCRYPT)]);

        $this->assertTrue(password_verify($plain, $user->passwordHash()));
    }
}
