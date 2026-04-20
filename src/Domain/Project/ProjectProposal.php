<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Tenant\TenantId;

final class ProjectProposal
{
    public function __construct(
        private readonly ProjectProposalId $id,
        private readonly TenantId $tenantId,
        private readonly string $userId,
        private readonly string $authorName,
        private readonly string $authorEmail,
        private readonly string $title,
        private readonly string $category,
        private readonly string $summary,
        private readonly string $description,
        private readonly string $status,
        private readonly string $createdAt,
    ) {}

    public function id(): ProjectProposalId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): string { return $this->userId; }
    public function authorName(): string { return $this->authorName; }
    public function authorEmail(): string { return $this->authorEmail; }
    public function title(): string { return $this->title; }
    public function category(): string { return $this->category; }
    public function summary(): string { return $this->summary; }
    public function description(): string { return $this->description; }
    public function status(): string { return $this->status; }
    public function createdAt(): string { return $this->createdAt; }
}
