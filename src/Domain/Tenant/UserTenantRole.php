<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

enum UserTenantRole: string
{
    case Admin      = 'admin';
    case Moderator  = 'moderator';
    case Member     = 'member';
    case Supporter  = 'supporter';
    case Registered = 'registered';

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

    public static function fromStringOrRegistered(string $value): self
    {
        return self::tryFrom($value) ?? self::Registered;
    }
}
