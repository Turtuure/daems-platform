<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

use Daems\Domain\Tenant\TenantId;

final class TenantGovernanceSettings
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly int $expulsionHearingDays,
        public readonly int $decisionExpirationDays,
        private readonly bool $requiresFormalDecisionForFees = false,
        private readonly int  $defaultDueDaysFromAnniversary = 60,
        private readonly int  $overdueGraceDays = 30,
        private readonly bool $lapseCheckEnabled = true,
    ) {
        if ($expulsionHearingDays < 1) {
            throw new \InvalidArgumentException('expulsionHearingDays must be >= 1');
        }
        if ($decisionExpirationDays < 1) {
            throw new \InvalidArgumentException('decisionExpirationDays must be >= 1');
        }
    }

    public function requiresFormalDecisionForFees(): bool { return $this->requiresFormalDecisionForFees; }
    public function defaultDueDaysFromAnniversary(): int  { return $this->defaultDueDaysFromAnniversary; }
    public function overdueGraceDays(): int               { return $this->overdueGraceDays; }
    public function lapseCheckEnabled(): bool             { return $this->lapseCheckEnabled; }
}
