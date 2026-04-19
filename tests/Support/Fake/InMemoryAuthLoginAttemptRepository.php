<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use DateTimeImmutable;

final class InMemoryAuthLoginAttemptRepository implements AuthLoginAttemptRepositoryInterface
{
    /** @var list<array{ip:string,email:string,success:bool,at:DateTimeImmutable}> */
    public array $rows = [];

    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void
    {
        $this->rows[] = ['ip' => $ip, 'email' => $email, 'success' => $success, 'at' => $at];
    }

    public function countFailuresSince(string $ip, string $email, DateTimeImmutable $since): int
    {
        $count = 0;
        foreach ($this->rows as $r) {
            if (!$r['success'] && $r['ip'] === $ip && $r['email'] === $email && $r['at'] >= $since) {
                $count++;
            }
        }
        return $count;
    }

    public function countFailuresByIpSince(string $ip, DateTimeImmutable $since): int
    {
        $count = 0;
        foreach ($this->rows as $r) {
            if (!$r['success'] && $r['ip'] === $ip && $r['at'] >= $since) {
                $count++;
            }
        }
        return $count;
    }
}
