<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

final class InviteToken
{
    public function __construct(
        public readonly string $raw,
        public readonly string $hash,
    ) {}

    public static function fromRaw(string $raw): self
    {
        return new self($raw, hash('sha256', $raw));
    }
}
