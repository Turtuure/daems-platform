<?php
declare(strict_types=1);

namespace Daems\Application\Governance;

use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionType;

interface BoardDecisionExecutorInterface
{
    public function decisionType(): BoardDecisionType;

    public function execute(BoardDecision $decision, \DateTimeImmutable $at): void;
}
