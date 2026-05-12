<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;

final class ExpireOverdueBoardDecisionsCron
{
    public function __construct(
        private readonly BoardDecisionRepositoryInterface $decisions,
    ) {}

    /**
     * Flips status=pending decisions whose expires_at < $at to Expired.
     * Returns count of decisions expired.
     */
    public function run(\DateTimeImmutable $at, int $batchSize = 100): int
    {
        $count = 0;
        foreach ($this->decisions->listExpiredPending($at, $batchSize) as $d) {
            $this->decisions->save($d->withStatus(BoardDecisionStatus::Expired, resolvedAt: $at));
            $count++;
        }
        return $count;
    }
}
