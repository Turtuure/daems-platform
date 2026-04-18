<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Application\User\UpdateProfile\UpdateProfileInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class UpdateProfileTest extends TestCase
{
    private function seed(InMemoryUserRepository $repo, string $email = 'jane@x.com'): User
    {
        $u = new User(
            UserId::generate(),
            'Jane',
            $email,
            password_hash('p', PASSWORD_BCRYPT),
            '1990-01-01',
        );
        $repo->save($u);
        return $u;
    }

    private function input(ActingUser $a, string $userId, string $firstName = 'J', string $email = 'j@x.com'): UpdateProfileInput
    {
        return new UpdateProfileInput($a, $userId, $firstName, 'D', $email, '1990-01-01', 'US', '', '', '', '');
    }

    public function testSelfUpdate(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser($u->id(), 'registered'), $u->id()->value()));
        $this->assertNull($out->error);
    }

    public function testUpdatingOtherForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser(UserId::generate(), 'registered'), $u->id()->value()));
    }

    public function testAdminCanUpdateAnyone(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser(UserId::generate(), 'admin'), $u->id()->value()));
        $this->assertNull($out->error);
    }

    public function testEmptyFirstNameReturnsValidationError(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser($u->id(), 'registered'), $u->id()->value(), firstName: ''));
        $this->assertNotNull($out->error);
    }

    public function testInvalidEmailReturnsValidationError(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser($u->id(), 'registered'), $u->id()->value(), email: 'not-an-email'));
        $this->assertNotNull($out->error);
    }

    public function testDuplicateEmailReturnsGenericError(): void
    {
        $repo = new InMemoryUserRepository();
        $target = $this->seed($repo, 'target@x.com');
        $other = $this->seed($repo, 'other@x.com');

        $out = (new UpdateProfile($repo))
            ->execute($this->input(
                new ActingUser($target->id(), 'registered'),
                $target->id()->value(),
                email: 'other@x.com',
            ));

        $this->assertNotNull($out->error);
        $this->assertStringNotContainsString('SQLSTATE', $out->error);
        $this->assertStringNotContainsString('Duplicate', $out->error);
    }
}
