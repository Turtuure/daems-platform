<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\User\Role;
use Daems\Domain\User\UserId;
use InvalidArgumentException;

final class ActingUser
{
    public readonly Role $role;

    /**
     * Accepts either a Role enum (preferred) or a string value that must
     * match a known role. Unknown strings throw — no silent demotion to
     * Registered on a typo, because that would re-open the role-typo
     * authorization bypass this type exists to prevent.
     */
    public function __construct(
        public readonly UserId $id,
        Role|string $role,
    ) {
        if (is_string($role)) {
            $resolved = Role::tryFrom($role);
            if ($resolved === null) {
                throw new InvalidArgumentException("Unknown role: {$role}");
            }
            $this->role = $resolved;
        } else {
            $this->role = $role;
        }
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function owns(UserId $id): bool
    {
        return $this->id->value() === $id->value();
    }
}
