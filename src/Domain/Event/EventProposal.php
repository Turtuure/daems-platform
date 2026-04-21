<?php

declare(strict_types=1);

namespace Daems\Domain\Event;

use Daems\Domain\Tenant\TenantId;

final class EventProposal
{
    public function __construct(
        private readonly EventProposalId $id,
        private readonly TenantId $tenantId,
        private readonly string $userId,
        private readonly string $authorName,
        private readonly string $authorEmail,
        private readonly string $title,
        private readonly string $eventDate,
        private readonly ?string $eventTime,
        private readonly ?string $location,
        private readonly bool $isOnline,
        private readonly string $description,
        private readonly string $sourceLocale,
        private readonly string $status,
        private readonly string $createdAt,
        private readonly ?string $decidedAt = null,
        private readonly ?string $decidedBy = null,
        private readonly ?string $decisionNote = null,
    ) {
    }

    public function id(): EventProposalId
    {
        return $this->id;
    }
    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }
    public function userId(): string
    {
        return $this->userId;
    }
    public function authorName(): string
    {
        return $this->authorName;
    }
    public function authorEmail(): string
    {
        return $this->authorEmail;
    }
    public function title(): string
    {
        return $this->title;
    }
    public function eventDate(): string
    {
        return $this->eventDate;
    }
    public function eventTime(): ?string
    {
        return $this->eventTime;
    }
    public function location(): ?string
    {
        return $this->location;
    }
    public function isOnline(): bool
    {
        return $this->isOnline;
    }
    public function description(): string
    {
        return $this->description;
    }
    public function sourceLocale(): string
    {
        return $this->sourceLocale;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function createdAt(): string
    {
        return $this->createdAt;
    }
    public function decidedAt(): ?string
    {
        return $this->decidedAt;
    }
    public function decidedBy(): ?string
    {
        return $this->decidedBy;
    }
    public function decisionNote(): ?string
    {
        return $this->decisionNote;
    }
}
