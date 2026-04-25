<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;

final class InMemoryUserTenantRepository implements UserTenantRepositoryInterface
{
    /** @var array<string, UserTenantRole> keyed by "{userId}:{tenantId}" */
    private array $roles = [];

    public function findRole(UserId $userId, TenantId $tenantId): ?UserTenantRole
    {
        return $this->roles[$this->key($userId, $tenantId)] ?? null;
    }

    public function attach(UserId $userId, TenantId $tenantId, UserTenantRole $role): void
    {
        $this->roles[$this->key($userId, $tenantId)] = $role;
    }

    public function detach(UserId $userId, TenantId $tenantId): void
    {
        unset($this->roles[$this->key($userId, $tenantId)]);
    }

    /** @return list<UserTenantRole> */
    public function rolesForUser(UserId $userId): array
    {
        $out = [];
        foreach ($this->roles as $key => $role) {
            if (str_starts_with($key, $userId->value() . ':')) {
                $out[] = $role;
            }
        }
        return $out;
    }

    /** Test-only helper: check by raw string values. */
    public function hasRole(string $userId, string $tenantId, string $role): bool
    {
        $key = $userId . ':' . $tenantId;
        $stored = $this->roles[$key] ?? null;
        return $stored !== null && $stored->value === $role;
    }

    public function markAllLeftForUser(string $userId, \DateTimeImmutable $now): void
    {
        // In the in-memory fake, we remove active memberships for the user
        // (the real SQL sets left_at; here we just track detachment by key removal).
        foreach (array_keys($this->roles) as $key) {
            if (str_starts_with($key, $userId . ':')) {
                unset($this->roles[$key]);
            }
        }
    }

    /**
     * @return array{
     *   total_members: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   new_members:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   supporters:    array{value: int, sparkline: list<array{date: string, value: int}>},
     *   inactive:      array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     */
    public function membershipStatsForTenant(TenantId $tenantId): array
    {
        // Count attached memberships (active) for this tenant. Supporters role
        // here approximates the SQL membership_type='supporter' check — the
        // fake does not track user.membership_type, so role is the closest
        // signal we have.
        $totalMembers = 0;
        $supporters   = 0;
        $tenantSuffix = ':' . $tenantId->value();
        foreach ($this->roles as $key => $role) {
            if (str_ends_with($key, $tenantSuffix)) {
                $totalMembers++;
                if ($role === UserTenantRole::Supporter) {
                    $supporters++;
                }
            }
        }

        // 30-day zero-filled sparkline (today = last entry).
        $base = new \DateTimeImmutable('today');
        $emptySeries = [];
        for ($i = 29; $i >= 0; $i--) {
            $emptySeries[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
        }

        return [
            'total_members' => ['value' => $totalMembers, 'sparkline' => $emptySeries],
            'new_members'   => ['value' => $totalMembers, 'sparkline' => $emptySeries], // fake: all joins counted as "new"
            'supporters'    => ['value' => $supporters,   'sparkline' => $emptySeries],
            'inactive'      => ['value' => 0,             'sparkline' => []], // filled by use case from audit repo
        ];
    }

    private function key(UserId $userId, TenantId $tenantId): string
    {
        return $userId->value() . ':' . $tenantId->value();
    }
}
