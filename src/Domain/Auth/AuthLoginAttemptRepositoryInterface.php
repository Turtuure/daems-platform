<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use DateTimeImmutable;

interface AuthLoginAttemptRepositoryInterface
{
    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void;

    public function countFailuresSince(string $ip, string $email, DateTimeImmutable $since): int;

    /** Count failed attempts from an IP across ALL emails — for per-IP secondary budget. */
    public function countFailuresByIpSince(string $ip, DateTimeImmutable $since): int;
}
