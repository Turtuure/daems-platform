<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

final class ProjectParticipant
{
    public function __construct(
        private readonly ProjectParticipantId $id,
        private readonly string $projectId,
        private readonly string $userId,
        private readonly string $joinedAt,
    ) {}

    public function id(): ProjectParticipantId { return $this->id; }
    public function projectId(): string { return $this->projectId; }
    public function userId(): string { return $this->userId; }
    public function joinedAt(): string { return $this->joinedAt; }
}
