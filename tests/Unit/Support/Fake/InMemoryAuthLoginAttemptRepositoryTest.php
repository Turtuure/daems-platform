<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Support\Fake;

use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryAuthLoginAttemptRepositoryTest extends TestCase
{
    public function testCountsOnlyFailuresInWindow(): void
    {
        $r = new InMemoryAuthLoginAttemptRepository();
        $r->record('1.1.1.1', 'x@y.com', false, new DateTimeImmutable('2026-04-19T10:00:00Z'));
        $r->record('1.1.1.1', 'x@y.com', false, new DateTimeImmutable('2026-04-19T10:05:00Z'));
        $r->record('1.1.1.1', 'x@y.com', true,  new DateTimeImmutable('2026-04-19T10:06:00Z'));
        $r->record('1.1.1.1', 'other@y.com', false, new DateTimeImmutable('2026-04-19T10:06:00Z'));
        $r->record('2.2.2.2', 'x@y.com', false, new DateTimeImmutable('2026-04-19T10:06:00Z'));

        $this->assertSame(2, $r->countFailuresSince('1.1.1.1', 'x@y.com', new DateTimeImmutable('2026-04-19T09:50:00Z')));
        $this->assertSame(0, $r->countFailuresSince('1.1.1.1', 'x@y.com', new DateTimeImmutable('2026-04-19T10:10:00Z')));
    }
}
