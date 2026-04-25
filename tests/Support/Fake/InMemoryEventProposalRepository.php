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

    public function pendingStatsForTenant(TenantId $tenantId): array
    {
        // Value: all-time pending count for tenant.
        $value = 0;
        foreach ($this->byId as $p) {
            if (!$p->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($p->status() === 'pending') {
                $value++;
            }
        }

        // Sparkline: BACKWARD 30 days of created_at, ALL statuses (incoming volume).
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }
        foreach ($this->byId as $p) {
            if (!$p->tenantId()->equals($tenantId)) {
                continue;
            }
            // createdAt is "Y-m-d H:i:s"; take date part.
            $d = substr($p->createdAt(), 0, 10);
            if (isset($days[$d])) {
                $days[$d]++;
            }
        }
        $sparkline = [];
        foreach ($days as $date => $value2) {
            $sparkline[] = ['date' => $date, 'value' => $value2];
        }

        return ['value' => $value, 'sparkline' => $sparkline];
    }
}
