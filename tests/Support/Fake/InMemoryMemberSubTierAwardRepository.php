<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MemberSubTierAward;
use Daems\Domain\Membership\MemberSubTierAwardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class InMemoryMemberSubTierAwardRepository implements MemberSubTierAwardRepositoryInterface
{
    /** @var array<string, MemberSubTierAward> */
    private array $byId = [];

    public function findActive(TenantId $tenantId, UserId $userId, \DateTimeImmutable $at): ?MemberSubTierAward
    {
        $candidates = [];
        foreach ($this->byId as $a) {
            if ($a->tenantId->value() !== $tenantId->value()) continue;
            if ($a->userId->value()   !== $userId->value())   continue;
            if (!$a->isActive($at))                            continue;
            $candidates[] = $a;
        }
        if (empty($candidates)) return null;
        usort($candidates, fn(MemberSubTierAward $x, MemberSubTierAward $y) => $y->awardedAt <=> $x->awardedAt);
        return $candidates[0];
    }

    public function listForUser(TenantId $tenantId, UserId $userId): array
    {
        $out = [];
        foreach ($this->byId as $a) {
            if ($a->tenantId->value() === $tenantId->value() && $a->userId->value() === $userId->value()) $out[] = $a;
        }
        usort($out, fn(MemberSubTierAward $x, MemberSubTierAward $y) => $y->awardedAt <=> $x->awardedAt);
        return $out;
    }

    public function listActiveForSubTierSlug(TenantId $tenantId, string $subTierSlug, \DateTimeImmutable $at): array
    {
        $out = [];
        foreach ($this->byId as $a) {
            if ($a->tenantId->value() === $tenantId->value()
                && $a->subTierSlug === $subTierSlug
                && $a->isActive($at)) {
                $out[] = $a;
            }
        }
        return $out;
    }

    public function save(MemberSubTierAward $award): void
    {
        $this->byId[$award->id->value()] = $award;
    }
}
