<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecisionType;

final class BoardDecisionExecutorRegistry
{
    /** @var array<string, BoardDecisionExecutorInterface> keyed by decision_type value */
    private array $byType = [];

    public function register(BoardDecisionExecutorInterface $executor): void
    {
        $this->byType[$executor->decisionType()->value] = $executor;
    }

    public function for(BoardDecisionType $type): BoardDecisionExecutorInterface
    {
        return $this->byType[$type->value]
            ?? throw new \RuntimeException("No executor registered for decision_type {$type->value}");
    }
}
