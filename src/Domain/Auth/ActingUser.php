<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\User\UserId;

final class ActingUser
{
    public function __construct(
        public readonly UserId $id,
        public readonly string $role,
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function owns(UserId $id): bool
    {
        return $this->id->value() === $id->value();
    }
}
