<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

final class MemberStatusAuditEntry
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $previousStatus,
        public readonly string $newStatus,
        public readonly string $reason,
        public readonly string $performedByName,
        public readonly string $createdAt,        // ISO-8601
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'previousStatus'  => $this->previousStatus,
            'newStatus'       => $this->newStatus,
            'reason'          => $this->reason,
            'performedByName' => $this->performedByName,
            'createdAt'       => $this->createdAt,
        ];
    }
}
