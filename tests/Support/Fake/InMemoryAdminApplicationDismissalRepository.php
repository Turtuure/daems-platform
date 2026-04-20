<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;

final class InMemoryAdminApplicationDismissalRepository implements AdminApplicationDismissalRepositoryInterface
{
    /** @var list<AdminApplicationDismissal> */
    private array $rows = [];

    public function save(AdminApplicationDismissal $d): void
    {
        foreach ($this->rows as $i => $existing) {
            if ($existing->adminId === $d->adminId && $existing->appId === $d->appId) {
                $this->rows[$i] = $d;
                return;
            }
        }
        $this->rows[] = $d;
    }

    public function deleteByAdminId(string $adminId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => $d->adminId !== $adminId
        ));
    }

    public function deleteByAppId(string $appId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => $d->appId !== $appId
        ));
    }

    public function listAppIdsDismissedByAdmin(string $adminId): array
    {
        return array_values(array_map(
            static fn (AdminApplicationDismissal $d): string => $d->appId,
            array_filter(
                $this->rows,
                static fn (AdminApplicationDismissal $d): bool => $d->adminId === $adminId
            )
        ));
    }
}
