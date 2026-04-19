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
            $u->role(),
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
            $u->role(),
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

    public function deleteById(string $id): void
    {
        $u = $this->byId[$id] ?? null;
        if ($u === null) {
            return;
        }
        unset($this->idByEmail[strtolower($u->email())]);
        unset($this->byId[$id]);
    }
}
