<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumModerationAuditEntry
{
    public const ACTION_DELETED           = 'deleted';
    public const ACTION_LOCKED            = 'locked';
    public const ACTION_UNLOCKED          = 'unlocked';
    public const ACTION_PINNED            = 'pinned';
    public const ACTION_UNPINNED          = 'unpinned';
    public const ACTION_EDITED            = 'edited';
    public const ACTION_CATEGORY_CREATED  = 'category_created';
    public const ACTION_CATEGORY_UPDATED  = 'category_updated';
    public const ACTION_CATEGORY_DELETED  = 'category_deleted';
    public const ACTION_WARNED            = 'warned';

    /**
     * @param array<string,mixed>|null $originalPayload
     * @param array<string,mixed>|null $newPayload
     */
    public function __construct(
        private readonly ForumModerationAuditId $id,
        private readonly TenantId $tenantId,
        private readonly string $targetType,
        private readonly string $targetId,
        private readonly string $action,
        private readonly ?array $originalPayload,
        private readonly ?array $newPayload,
        private readonly ?string $reason,
        private readonly string $performedBy,
        private readonly ?string $relatedReportId,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumModerationAuditId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function action(): string { return $this->action; }
    /** @return array<string,mixed>|null */
    public function originalPayload(): ?array { return $this->originalPayload; }
    /** @return array<string,mixed>|null */
    public function newPayload(): ?array { return $this->newPayload; }
    public function reason(): ?string { return $this->reason; }
    public function performedBy(): string { return $this->performedBy; }
    public function relatedReportId(): ?string { return $this->relatedReportId; }
    public function createdAt(): string { return $this->createdAt; }
}
