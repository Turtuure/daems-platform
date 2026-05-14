<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class InMemoryMemberFeeInvoiceRepository implements MemberFeeInvoiceRepositoryInterface
{
    /** @var array<string, MemberFeeInvoice> */
    private array $byId = [];

    public function save(MemberFeeInvoice $invoice): void
    {
        $this->byId[$invoice->id()->value()] = $invoice;
    }

    public function findById(MemberFeeInvoiceId $id): ?MemberFeeInvoice
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findFor(TenantId $tenantId, UserId $userId, int $year): ?MemberFeeInvoice
    {
        foreach ($this->byId as $inv) {
            if ($inv->tenantId()->equals($tenantId)
                && $inv->userId()->equals($userId)
                && $inv->year() === $year
            ) {
                return $inv;
            }
        }
        return null;
    }

    public function findOverdueCandidates(TenantId $tenantId, DateTimeImmutable $asOf, int $graceDays): array
    {
        $cutoff = $asOf->modify("-{$graceDays} days");
        $out = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($inv->status() !== MemberFeeInvoiceStatus::Pending) {
                continue;
            }
            if ($inv->dueDate() >= $cutoff) {
                continue;
            }
            $out[] = $inv;
        }
        return array_values($out);
    }

    public function findUsersWithConsecutiveOverdueYears(TenantId $tenantId): array
    {
        /** @var array<string, list<int>> $byUser */
        $byUser = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($inv->status() !== MemberFeeInvoiceStatus::Overdue) {
                continue;
            }
            $uid = $inv->userId()->value();
            $byUser[$uid] = $byUser[$uid] ?? [];
            $byUser[$uid][] = $inv->year();
        }
        $out = [];
        foreach ($byUser as $uid => $years) {
            sort($years);
            $count = count($years);
            for ($i = 0; $i < $count - 1; $i++) {
                if ($years[$i + 1] === $years[$i] + 1) {
                    $out[] = ['user_id' => UserId::fromString($uid), 'years' => [$years[$i], $years[$i + 1]]];
                    break;
                }
            }
        }
        return array_values($out);
    }

    public function listForTenant(TenantId $tenantId, array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $matched = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) {
                continue;
            }
            if (isset($filter['year']) && $inv->year() !== (int) $filter['year']) {
                continue;
            }
            if (isset($filter['status']) && $inv->status()->value !== $filter['status']) {
                continue;
            }
            if (isset($filter['fee_type']) && $inv->feeType()->value !== $filter['fee_type']) {
                continue;
            }
            if (isset($filter['user_id']) && $inv->userId()->value() !== $filter['user_id']) {
                continue;
            }
            $matched[] = $inv;
        }
        return array_values(array_slice($matched, $offset, $limit));
    }

    public function findByReference(TenantId $tenantId, string $reference): ?MemberFeeInvoice
    {
        $found = null;
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) {
                continue;
            }
            if (!$inv->status()->isOpen()) {
                continue;
            }
            if (!str_starts_with($inv->id()->value(), $reference)) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $inv;
        }
        return $found;
    }

    public function findInvoicesDueOn(TenantId $tenantId, DateTimeImmutable $dueDate, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        $target = $dueDate->format('Y-m-d');
        $out = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) {
                continue;
            }
            if ($inv->dueDate()->format('Y-m-d') !== $target) {
                continue;
            }
            if (!in_array($inv->status(), $statuses, true)) {
                continue;
            }
            $out[] = $inv;
        }
        return array_values($out);
    }

    public function listOpenForUser(TenantId $tenantId, UserId $userId): array
    {
        $out = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) {
                continue;
            }
            if (!$inv->userId()->equals($userId)) {
                continue;
            }
            if (!$inv->status()->isOpen()) {
                continue;
            }
            $out[] = $inv;
        }
        return array_values($out);
    }
}
