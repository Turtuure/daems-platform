<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\User\User;
use Daems\Domain\User\UserRepositoryInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> keyed by id */
    public array $byId = [];

    /** @var array<string, string> email lowercased → id */
    public array $idByEmail = [];

    public function findByEmail(string $email): ?User
    {
        $id = $this->idByEmail[strtolower($email)] ?? null;
        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    public function findById(string $id): ?User
    {
        return $this->byId[$id] ?? null;
    }

    public function save(User $user): void
    {
        $key = strtolower($user->email());
        $existingId = $this->idByEmail[$key] ?? null;
        if ($existingId !== null && $existingId !== $user->id()->value()) {
            // Mirror SqlUserRepository's ValidationException on duplicate email.
            // Without this, tests could seed two users with the same email
            // and pass while production would fail.
            throw new \Daems\Domain\Shared\ValidationException('Invalid email.');
        }
        $this->byId[$user->id()->value()] = $user;
        $this->idByEmail[$key] = $user->id()->value();
    }

    public function createActivated(string $userId, array $fields, \DateTimeImmutable $now): User
    {
        $user = new User(
            \Daems\Domain\User\UserId::fromString($userId),
            $fields['name'],
            $fields['email'],
            null,
            $fields['date_of_birth'],
            $fields['country'],
            membershipType:   $fields['membership_type'],
            membershipStatus: $fields['membership_status'],
            memberNumber:     $fields['member_number'],
            createdAt:        $now->format('Y-m-d H:i:s'),
        );
        $this->save($user);
        return $user;
    }

    public function updateProfile(string $id, array $fields): void
    {
        $u = $this->byId[$id] ?? null;
        if ($u === null) {
            return;
        }

        $email = isset($fields['email']) ? (string) $fields['email'] : $u->email();
        $duplicate = $this->idByEmail[strtolower($email)] ?? null;
        if ($duplicate !== null && $duplicate !== $id) {
            throw new \Daems\Domain\Shared\ValidationException('Invalid email.');
        }

        $new = new User(
            $u->id(),
            isset($fields['name']) ? (string) $fields['name'] : $u->name(),
            $email,
            $u->passwordHash(),
            isset($fields['date_of_birth']) ? (string) $fields['date_of_birth'] : $u->dateOfBirth(),
            isset($fields['country']) ? (string) $fields['country'] : $u->country(),
            isset($fields['address_street']) ? (string) $fields['address_street'] : $u->addressStreet(),
            isset($fields['address_zip']) ? (string) $fields['address_zip'] : $u->addressZip(),
            isset($fields['address_city']) ? (string) $fields['address_city'] : $u->addressCity(),
            isset($fields['address_country']) ? (string) $fields['address_country'] : $u->addressCountry(),
            $u->membershipType(),
            $u->membershipStatus(),
            $u->memberNumber(),
            $u->createdAt(),
        );

        unset($this->idByEmail[strtolower($u->email())]);
        $this->byId[$id] = $new;
        $this->idByEmail[strtolower($new->email())] = $id;
    }

    public function updatePassword(string $id, string $newHash): void
    {
        $u = $this->byId[$id] ?? null;
        if ($u === null) {
            return;
        }
        $this->byId[$id] = new User(
            $u->id(),
            $u->name(),
            $u->email(),
            $newHash,
            $u->dateOfBirth(),
            $u->country(),
            $u->addressStreet(),
            $u->addressZip(),
            $u->addressCity(),
            $u->addressCountry(),
            $u->membershipType(),
            $u->membershipStatus(),
            $u->memberNumber(),
            $u->createdAt(),
        );
    }

    public function updatePublicAvatarVisible(string $id, bool $visible): void
    {
        $u = $this->byId[$id] ?? null;
        if ($u === null) {
            return;
        }
        $this->byId[$id] = new User(
            $u->id(), $u->name(), $u->email(), $u->passwordHash(), $u->dateOfBirth(),
            $u->country(), $u->addressStreet(), $u->addressZip(), $u->addressCity(), $u->addressCountry(),
            $u->membershipType(), $u->membershipStatus(), $u->memberNumber(), $u->createdAt(),
            $u->isPlatformAdmin(), $u->deletedAt(), $visible,
        );
    }

    public function deleteById(string $id): void
    {
        $u = $this->byId[$id] ?? null;
        if ($u === null) {
            return;
        }
        unset($this->idByEmail[strtolower($u->email())]);
        unset($this->byId[$id]);
    }

    public function anonymise(string $userId, \DateTimeImmutable $now): void
    {
        $u = $this->byId[$userId] ?? null;
        if ($u === null) {
            return;
        }
        $anonEmail = 'anon-' . $userId . '@anon.local';
        unset($this->idByEmail[strtolower($u->email())]);
        $anon = new \Daems\Domain\User\User(
            id:              $u->id(),
            name:            'Anonyymi',
            email:           $anonEmail,
            passwordHash:    null,
            dateOfBirth:     null,
            country:         '',
            addressStreet:   '',
            addressZip:      '',
            addressCity:     '',
            addressCountry:  '',
            membershipType:  $u->membershipType(),
            membershipStatus: 'terminated',
            memberNumber:    $u->memberNumber(),
            createdAt:       $u->createdAt(),
            isPlatformAdmin: $u->isPlatformAdmin(),
            deletedAt:       $now,
        );
        $this->byId[$userId] = $anon;
        $this->idByEmail[strtolower($anonEmail)] = $userId;
    }
}
