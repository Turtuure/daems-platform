<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumReport
{
    public const TARGET_POST  = 'post';
    public const TARGET_TOPIC = 'topic';

    public const STATUS_OPEN      = 'open';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    public const REASON_CATEGORIES = [
        'spam','harassment','off_topic','hate_speech','misinformation','other',
    ];

    public function __construct(
        private readonly ForumReportId $id,
        private readonly TenantId $tenantId,
        private readonly string $targetType,
        private readonly string $targetId,
        private readonly string $reporterUserId,
        private readonly string $reasonCategory,
        private readonly ?string $reasonDetail,
        private readonly string $status,
        private readonly ?string $resolvedAt,
        private readonly ?string $resolvedBy,
        private readonly ?string $resolutionNote,
        private readonly ?string $resolutionAction,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumReportId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function reporterUserId(): string { return $this->reporterUserId; }
    public function reasonCategory(): string { return $this->reasonCategory; }
    public function reasonDetail(): ?string { return $this->reasonDetail; }
    public function status(): string { return $this->status; }
    public function resolvedAt(): ?string { return $this->resolvedAt; }
    public function resolvedBy(): ?string { return $this->resolvedBy; }
    public function resolutionNote(): ?string { return $this->resolutionNote; }
    public function resolutionAction(): ?string { return $this->resolutionAction; }
    public function createdAt(): string { return $this->createdAt; }
}
