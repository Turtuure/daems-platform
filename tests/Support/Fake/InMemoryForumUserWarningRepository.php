<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Forum\ForumUserWarning;
use Daems\Domain\Forum\ForumUserWarningRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryForumUserWarningRepository implements ForumUserWarningRepositoryInterface
{
    /** @var list<ForumUserWarning> */
    private array $warnings = [];

    public function record(ForumUserWarning $warning): void
    {
        $this->warnings[] = $warning;
    }

    /** @return list<ForumUserWarning> */
    public function listForUserForTenant(string $userId, TenantId $tenantId): array
    {
        $filtered = array_filter(
            $this->warnings,
            static fn (ForumUserWarning $w): bool =>
                $w->userId() === $userId && $w->tenantId()->equals($tenantId),
        );

        $sorted = array_values($filtered);
        usort(
            $sorted,
            static fn (ForumUserWarning $a, ForumUserWarning $b): int => strcmp($b->createdAt(), $a->createdAt()),
        );

        return $sorted;
    }

    /** @return list<ForumUserWarning> */
    public function all(): array
    {
        return $this->warnings;
    }
}
