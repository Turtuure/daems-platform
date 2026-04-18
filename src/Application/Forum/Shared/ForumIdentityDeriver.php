<?php

declare(strict_types=1);

namespace Daems\Application\Forum\Shared;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\UserRepositoryInterface;

final class ForumIdentityDeriver
{
    /**
     * @return array{user_id:string, author_name:string, avatar_initials:string, avatar_color:string, role:string, role_class:string, joined_text:string}
     */
    public static function derive(ActingUser $acting, UserRepositoryInterface $users): array
    {
        $user = $users->findById($acting->id->value());
        $name = $user !== null ? $user->name() : 'Unknown';
        $role = $user !== null ? $user->role() : 'registered';
        $createdAt = $user !== null ? $user->createdAt() : '';

        return [
            'user_id'         => $acting->id->value(),
            'author_name'     => $name,
            'avatar_initials' => self::initials($name),
            'avatar_color'    => '#64748b',
            'role'            => self::roleLabel($role),
            'role_class'      => 'role-' . strtolower($role),
            'joined_text'     => $createdAt !== '' ? 'Joined ' . substr($createdAt, 0, 10) : '',
        ];
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'admin'      => 'Administrator',
            'moderator'  => 'Moderator',
            'member'     => 'Member',
            'supporter'  => 'Supporter',
            default      => 'Member',
        };
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $p) {
            if ($p !== '') {
                $letters .= strtoupper(substr($p, 0, 1));
            }
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters === '' ? '??' : $letters;
    }
}
