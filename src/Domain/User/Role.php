<?php

declare(strict_types=1);

namespace Daems\Domain\User;

/**
 * Closed set of application roles. A typo that demotes someone to a
 * non-admin is a security-relevant failure mode, so the vocabulary is
 * enforced by the type system rather than ad-hoc string comparison.
 *
 * Stored in the `users.role` column as the string value.
 */
enum Role: string
{
    case Admin      = 'admin';
    case Moderator  = 'moderator';
    case Member     = 'member';
    case Supporter  = 'supporter';
    case Registered = 'registered';

    /** Human-readable badge label used by the forum. */
    public function label(): string
    {
        return match ($this) {
            self::Admin      => 'Administrator',
            self::Moderator  => 'Moderator',
            self::Member     => 'Member',
            self::Supporter  => 'Supporter',
            self::Registered => 'Member',
        };
    }

    /**
     * Tolerant parse: unknown strings fall back to Registered so a row
     * with an unexpected value (from a future migration, a manual INSERT,
     * etc.) stays authenticated but does NOT accidentally become admin.
     */
    public static function fromStringOrRegistered(string $value): self
    {
        return self::tryFrom($value) ?? self::Registered;
    }
}
