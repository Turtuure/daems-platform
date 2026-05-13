<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

use DateTimeImmutable;

/**
 * JSONL logger for cron commands. Writes one line per event to
 * `<logDir>/<command-name-sanitized>-YYYY-MM-DD.log`. Each line:
 *   {"ts":"2026-05-15T03:00:01+0300","level":"info","tenant":"daems",...payload}
 *
 * Errors get level=error. The file is opened per write (append mode) to keep
 * cleanup trivial — no fd to leak when the cron exits.
 */
final class CronLogger
{
    private readonly string $filename;

    public function __construct(
        string $logDir,
        string $commandName,
        DateTimeImmutable $today,
    ) {
        $safe = preg_replace('/[^a-z0-9._-]/i', '-', $commandName) ?? 'cmd';
        $this->filename = $logDir . '/' . $safe . '-' . $today->format('Y-m-d') . '.log';
    }

    /** @param array<string,mixed> $payload */
    public function info(array $payload): void
    {
        $this->write('info', $payload);
    }

    /** @param array<string,mixed> $payload */
    public function error(array $payload): void
    {
        $this->write('error', $payload);
    }

    /** @param array<string,mixed> $payload */
    private function write(string $level, array $payload): void
    {
        $row = array_merge(
            ['ts' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM), 'level' => $level],
            $payload,
        );
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = json_encode(['ts' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM), 'level' => 'error', 'message' => 'json_encode failed']);
        }
        file_put_contents($this->filename, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
