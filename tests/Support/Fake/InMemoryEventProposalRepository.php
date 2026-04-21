<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryEventProposalRepository implements EventProposalRepositoryInterface
{
    /** @var array<string, EventProposal> keyed by id */
    public array $byId = [];

    public function save(EventProposal $proposal): void
    {
        $this->byId[$proposal->id()->value()] = $proposal;
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?EventProposal
    {
        $p = $this->byId[$id] ?? null;
        if ($p === null) {
            return null;
        }
        return $p->tenantId()->equals($tenantId) ? $p : null;
    }

    public function listForTenant(TenantId $tenantId, ?string $status = null): array
    {
        $out = [];
        foreach ($this->byId as $p) {
            if (!$p->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($status !== null && $p->status() !== $status) {
                continue;
            }
            $out[] = $p;
        }
        // Sort by createdAt desc
        usort($out, static fn(EventProposal $a, EventProposal $b): int => strcmp($b->createdAt(), $a->createdAt()));
        return $out;
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
        $p = $this->findByIdForTenant($id, $tenantId);
        if ($p === null) {
            return;
        }
        $this->byId[$id] = new EventProposal(
            $p->id(),
            $p->tenantId(),
            $p->userId(),
            $p->authorName(),
            $p->authorEmail(),
            $p->title(),
            $p->eventDate(),
            $p->eventTime(),
            $p->location(),
            $p->isOnline(),
            $p->description(),
            $p->sourceLocale(),
            $decision,
            $p->createdAt(),
            $now->format('Y-m-d H:i:s'),
            $decidedBy,
            $note,
        );
    }
}
