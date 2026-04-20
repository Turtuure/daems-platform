<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DecideApplication;

final class DecideApplicationOutput
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $activatedUserId = null,
        public readonly ?string $memberNumber = null,
        public readonly ?string $inviteUrl = null,
        public readonly ?string $inviteExpiresAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'success'           => $this->success,
            'activated_user_id' => $this->activatedUserId,
            'member_number'     => $this->memberNumber,
            'invite_url'        => $this->inviteUrl,
            'invite_expires_at' => $this->inviteExpiresAt,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
