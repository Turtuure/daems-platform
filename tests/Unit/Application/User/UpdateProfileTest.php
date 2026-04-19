<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Application\User\UpdateProfile\UpdateProfileInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class UpdateProfileTest extends TestCase
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

    private function seed(InMemoryUserRepository $repo, string $email = 'jane@x.com'): User
    {
        $u = new User(
            UserId::generate(),
            'Jane Doe',
            $email,
            password_hash('p', PASSWORD_BCRYPT),
            '1990-01-01',
            'US',
            'Street 1',
            '00100',
            'Helsinki',
            'FI',
        );
        $repo->save($u);
        return $u;
    }

    private function input(ActingUser $a, string $userId, ?string $firstName = 'J', ?string $email = 'j@x.com'): UpdateProfileInput
    {
        return new UpdateProfileInput(
            acting: $a,
            userId: $userId,
            firstName: $firstName,
            lastName: 'D',
            email: $email,
            dob: '1990-01-01',
            country: 'US',
            addressStreet: '',
            addressZip: '',
            addressCity: '',
            addressCountry: '',
        );
    }

    public function testSelfUpdate(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input($this->acting($u->id()), $u->id()->value()));
        $this->assertNull($out->error);
    }

    public function testUpdatingOtherForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        (new UpdateProfile($repo))
            ->execute($this->input($this->acting(UserId::generate()), $u->id()->value()));
    }

    public function testAdminCanUpdateAnyone(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input($this->acting(UserId::generate(), 'admin'), $u->id()->value()));
        $this->assertNull($out->error);
    }

    public function testEmptyFirstNameReturnsValidationError(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input($this->acting($u->id()), $u->id()->value(), firstName: ''));
        $this->assertNotNull($out->error);
    }

    public function testInvalidEmailReturnsValidationError(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $out = (new UpdateProfile($repo))
            ->execute($this->input($this->acting($u->id()), $u->id()->value(), email: 'not-an-email'));
        $this->assertNotNull($out->error);
    }

    public function testDuplicateEmailReturnsGenericError(): void
    {
        $repo = new InMemoryUserRepository();
        $target = $this->seed($repo, 'target@x.com');
        $this->seed($repo, 'other@x.com');

        $out = (new UpdateProfile($repo))
            ->execute($this->input(
                $this->acting($target->id()),
                $target->id()->value(),
                email: 'other@x.com',
            ));

        $this->assertNotNull($out->error);
        $this->assertStringNotContainsString('SQLSTATE', $out->error);
        $this->assertStringNotContainsString('Duplicate', $out->error);
    }

    /**
     * Regression for SAST F-002 residual: an update call that omits a field
     * must not wipe the stored value. Previous behavior: every missing field
     * was coerced to empty string and written unconditionally.
     */
    public function testOmittedFieldsAreNotWiped(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);

        $out = (new UpdateProfile($repo))->execute(new UpdateProfileInput(
            acting: $this->acting($u->id()),
            userId: $u->id()->value(),
            firstName: 'Alex',
        ));
        $this->assertNull($out->error);

        $reloaded = $repo->findById($u->id()->value());
        $this->assertNotNull($reloaded);
        $this->assertSame('Alex Doe', $reloaded->name(), 'first_name update; last_name preserved');
        $this->assertSame('jane@x.com', $reloaded->email(), 'email unchanged');
        $this->assertSame('1990-01-01', $reloaded->dateOfBirth(), 'dob unchanged');
        $this->assertSame('Street 1', $reloaded->addressStreet(), 'address_street unchanged');
        $this->assertSame('00100', $reloaded->addressZip(), 'address_zip unchanged');
        $this->assertSame('Helsinki', $reloaded->addressCity(), 'address_city unchanged');
        $this->assertSame('US', $reloaded->country(), 'country unchanged');
    }

    public function testExplicitEmptyStringSetsFieldToEmpty(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);

        $out = (new UpdateProfile($repo))->execute(new UpdateProfileInput(
            acting: $this->acting($u->id()),
            userId: $u->id()->value(),
            addressStreet: '',
        ));
        $this->assertNull($out->error);
        $this->assertSame('', $repo->findById($u->id()->value())->addressStreet());
    }

    public function testNoFieldsProvidedIsNoop(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);

        $out = (new UpdateProfile($repo))->execute(new UpdateProfileInput(
            acting: $this->acting($u->id()),
            userId: $u->id()->value(),
        ));
        $this->assertNull($out->error);
        $this->assertSame('Jane Doe', $repo->findById($u->id()->value())->name());
    }
}
