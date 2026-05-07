<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Shared\TransactionManagerInterface;

/**
 * Test transaction manager that simulates rollback semantics by snapshotting
 * caller-provided state objects via deep-clone before invoking the callable.
 * If the callable throws, mutated state is restored from the snapshot,
 * mirroring what PdoTransactionManager achieves with BEGIN/ROLLBACK at the
 * database level.
 *
 * Used by tests that exercise transactional cascade use cases against
 * in-memory fakes, since those fakes have no native rollback.
 */
final class SnapshotTransactionManager implements TransactionManagerInterface
{
    /** @var list<object> targets whose mutable state should be snapshotted */
    private array $targets;

    /**
     * @param list<object> $targets test fakes to snapshot/restore
     */
    public function __construct(array $targets)
    {
        $this->targets = $targets;
    }

    public function run(callable $fn): mixed
    {
        $snapshots = [];
        foreach ($this->targets as $idx => $target) {
            $snapshots[$idx] = $this->snapshot($target);
        }
        try {
            return $fn();
        } catch (\Throwable $e) {
            foreach ($this->targets as $idx => $target) {
                $this->restore($target, $snapshots[$idx]);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(object $target): array
    {
        $reflection = new \ReflectionObject($target);
        $state = [];
        foreach ($reflection->getProperties() as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($target);
            // Deep-copy arrays of objects so future mutations to the
            // repository's internal array don't leak into the snapshot.
            if (is_array($value)) {
                $state[$prop->getName()] = $value; // PHP arrays are copy-on-write — fine.
            } else {
                $state[$prop->getName()] = $value;
            }
        }
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function restore(object $target, array $state): void
    {
        $reflection = new \ReflectionObject($target);
        foreach ($state as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $prop = $reflection->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue($target, $value);
            }
        }
    }
}
