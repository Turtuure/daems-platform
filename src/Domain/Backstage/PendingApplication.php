<?php

declare(strict_types=1);

namespace Daems\Domain\Backstage;

final class PendingApplication
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,             // 'member' | 'supporter'
        public readonly string $displayName,
        public readonly string $email,
        public readonly string $submittedAt,      // ISO-8601
        public readonly string $motivation,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'displayName' => $this->displayName,
            'email'       => $this->email,
            'submittedAt' => $this->submittedAt,
            'motivation'  => $this->motivation,
        ];
    }
}
