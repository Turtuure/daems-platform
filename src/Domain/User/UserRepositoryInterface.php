<?php

declare(strict_types=1);

namespace Daems\Domain\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findById(string $id): ?User;

    public function save(User $user): void;

    /**
     * Insert a user that is active but has no password yet (pending invite).
     * Returns the persisted domain object.
     *
     * @param array{
     *     name: string,
     *     email: string,
     *     date_of_birth: ?string,
     *     country: string,
     *     membership_type: string,
     *     membership_status: string,
     *     member_number: ?string
     * } $fields
     */
    public function createActivated(string $userId, array $fields, \DateTimeImmutable $now): User;

    public function updateProfile(string $id, array $fields): void;

    public function updatePassword(string $id, string $newHash): void;

    public function updatePublicAvatarVisible(string $id, bool $visible): void;

    public function deleteById(string $id): void;

    public function anonymise(string $userId, \DateTimeImmutable $now): void;
}
