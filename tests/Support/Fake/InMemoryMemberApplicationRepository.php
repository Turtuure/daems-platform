<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use DateTimeImmutable;

final class InMemoryMemberApplicationRepository implements MemberApplicationRepositoryInterface
{
    /** @var list<MemberApplication> */
    public array $applications = [];

    /** @var array<string, array{decision:string, by:string, note:?string, at:string}> */
    public array $decisions = [];

    public function save(MemberApplication $application): void
    {
        $this->applications[] = $application;
    }

    public function listPendingForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit): array
    {
        $filtered = array_filter(
            $this->applications,
            static fn (MemberApplication $a): bool => $a->tenantId()->equals($tenantId) && $a->status() === 'pending',
        );
        return array_slice(array_values($filtered), 0, $limit);
    }

    public function findByIdForTenant(string $id, \Daems\Domain\Tenant\TenantId $tenantId): ?MemberApplication
    {
        foreach ($this->applications as $a) {
            if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
                return $a;
            }
        }
        return null;
    }

    public function recordDecision(
        string $id,
        \Daems\Domain\Tenant\TenantId $tenantId,
        string $decision,
        \Daems\Domain\User\UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void {
        foreach ($this->applications as $i => $a) {
            if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
                $this->applications[$i] = new MemberApplication(
                    $a->id(), $a->tenantId(), $a->name(), $a->email(),
                    $a->dateOfBirth(), $a->country(), $a->motivation(), $a->howHeard(),
                    $decision, $a->createdAt(),
                );
                $this->decisions[$id] = ['decision' => $decision, 'by' => $decidedBy->value(), 'note' => $note, 'at' => $decidedAt->format('Y-m-d H:i:s')];
                return;
            }
        }
    }
}
