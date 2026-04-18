<?php

declare(strict_types=1);

namespace Daems\Domain\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findById(string $id): ?User;

    public function save(User $user): void;

    public function updateProfile(string $id, array $fields): void;

    public function updatePassword(string $id, string $newHash): void;

    public function deleteById(string $id): void;
}
