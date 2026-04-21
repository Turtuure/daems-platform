<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryProjectProposalRepository implements ProjectProposalRepositoryInterface
{
    /** @var list<ProjectProposal> */
    public array $proposals = [];

    /** @var array<string, ProjectProposal> keyed by id */
    public array $byId = [];

    public function save(ProjectProposal $proposal): void
    {
        $this->proposals[] = $proposal;
        $this->byId[$proposal->id()->value()] = $proposal;
    }

    public function lastProposal(): ?ProjectProposal
    {
        return $this->proposals === [] ? null : $this->proposals[array_key_last($this->proposals)];
    }

    public function listPendingForTenant(TenantId $tenantId): array
    {
        $matches = array_values(array_filter(
            $this->proposals,
            static fn(ProjectProposal $p): bool => $p->tenantId()->equals($tenantId) && $p->status() === 'pending',
        ));
        usort($matches, static fn(ProjectProposal $a, ProjectProposal $b): int => strcmp($b->createdAt(), $a->createdAt()));
        return $matches;
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?ProjectProposal
    {
        $p = $this->byId[$id] ?? null;
        if ($p === null) {
            return null;
        }
        return $p->tenantId()->equals($tenantId) ? $p : null;
    }

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        string $decidedBy,
        ?string $note,
        \DateTimeImmutable $now,
    ): void {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \DomainException('invalid_decision');
        }
        $existing = $this->findByIdForTenant($id, $tenantId);
        if ($existing === null) {
            return;
        }
        $updated = new ProjectProposal(
            $existing->id(),
            $existing->tenantId(),
            $existing->userId(),
            $existing->authorName(),
            $existing->authorEmail(),
            $existing->title(),
            $existing->category(),
            $existing->summary(),
            $existing->description(),
            $decision,
            $existing->createdAt(),
            $now->format('Y-m-d H:i:s'),
            $decidedBy,
            $note,
            $existing->sourceLocale(),
        );
        $this->byId[$id] = $updated;
        foreach ($this->proposals as $i => $p) {
            if ($p->id()->value() === $id) {
                $this->proposals[$i] = $updated;
                break;
            }
        }
    }
}
