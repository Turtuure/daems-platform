<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Member\PublicMemberProfile;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlPublicMemberRepository implements PublicMemberRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findByMemberNumber(string $memberNumber): ?PublicMemberProfile
    {
        $row = $this->db->queryOne(
            'SELECT u.id, u.name, u.member_number, u.membership_type,
                    u.public_avatar_visible, u.membership_started_at,
                    t.slug AS tenant_slug, t.name AS tenant_name, t.member_number_prefix,
                    ut.role
             FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             JOIN tenants t ON t.id = ut.tenant_id
             WHERE u.member_number = ? AND u.deleted_at IS NULL
             ORDER BY ut.joined_at ASC
             LIMIT 1',
            [$memberNumber],
        );
        if ($row === null) return null;

        $name = (string) ($row['name'] ?? '');
        $initials = self::deriveInitials($name);
        $visible = (bool) ($row['public_avatar_visible'] ?? 1);
        $userId = (string) ($row['id'] ?? '');

        return new PublicMemberProfile(
            memberNumberRaw: (string) ($row['member_number'] ?? ''),
            name: $name,
            memberType: (string) ($row['membership_type'] ?? 'basic'),
            role: isset($row['role']) && is_string($row['role']) ? $row['role'] : null,
            joinedAt: isset($row['membership_started_at']) && is_string($row['membership_started_at'])
                ? substr($row['membership_started_at'], 0, 10) : null,
            tenantSlug: (string) ($row['tenant_slug'] ?? ''),
            tenantName: (string) ($row['tenant_name'] ?? ''),
            tenantMemberNumberPrefix: isset($row['member_number_prefix']) && is_string($row['member_number_prefix'])
                ? $row['member_number_prefix'] : null,
            publicAvatarVisible: $visible,
            avatarInitials: $initials,
            avatarUrl: $visible ? self::avatarPublicUrl($userId) : null,
        );
    }

    private static function deriveInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = isset($parts[0]) ? mb_substr($parts[0], 0, 1) : '';
        $last  = '';
        $count = count($parts);
        if ($count > 1) {
            $last = mb_substr($parts[$count - 1], 0, 1);
        }
        $out = strtoupper($first . $last);
        return $out !== '' ? $out : '?';
    }

    private static function avatarPublicUrl(string $userId): ?string
    {
        $safeId = preg_replace('/[^a-f0-9\-]/', '', $userId);
        if (!is_string($safeId) || $safeId === '') return null;
        return '/uploads/avatars/' . $safeId . '.webp';
    }
}
