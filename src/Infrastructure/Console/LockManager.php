<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

/**
 * Exclusive file lock per command. Used by cron-runners to prevent overlapping
 * runs when a previous invocation is still in-flight (e.g. anniversary-cron
 * fires every minute while a long batch is still processing).
 *
 * Implementation: flock(LOCK_EX|LOCK_NB) on `<lockDir>/<command>.lock`.
 * If the lock cannot be acquired, acquire() returns false — caller exits 0
 * with a log line. Lock is released when release() is called OR the PHP
 * process terminates (OS releases flock on fd close).
 */
final class LockManager
{
    /** @var array<string,resource> */
    private array $handles = [];

    public function __construct(private readonly string $lockDir) {}

    public function acquire(string $commandName): bool
    {
        $path = $this->path($commandName);
        $fp = fopen($path, 'c');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open lock file: {$path}");
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        $this->handles[$commandName] = $fp;
        // Write current PID for debugging.
        ftruncate($fp, 0);
        fwrite($fp, (string) getmypid());
        fflush($fp);
        return true;
    }

    public function release(string $commandName): void
    {
        if (!isset($this->handles[$commandName])) {
            return;
        }
        $fp = $this->handles[$commandName];
        flock($fp, LOCK_UN);
        fclose($fp);
        unset($this->handles[$commandName]);
    }

    private function path(string $commandName): string
    {
        // Sanitize command name: only safe filename chars.
        $safe = preg_replace('/[^a-z0-9._-]/i', '_', $commandName) ?? 'cmd';
        return $this->lockDir . '/' . $safe . '.lock';
    }
}
