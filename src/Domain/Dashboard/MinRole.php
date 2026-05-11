<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

enum MinRole: string
{
    case Member    = 'member';
    case Moderator = 'moderator';
    case Admin     = 'admin';
    case Gsa       = 'gsa';

    /** Compares roles by hierarchy: gsa > admin > moderator > member. */
    public function isReachableBy(self $userRole): bool
    {
        return self::rank($userRole) >= self::rank($this);
    }

    private static function rank(self $r): int
    {
        return match ($r) {
            self::Member    => 0,
            self::Moderator => 1,
            self::Admin     => 2,
            self::Gsa       => 3,
        };
    }
}
