<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CronLogger;
use PHPUnit\Framework\TestCase;

final class CronLoggerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/cronlog-test-' . uniqid();
        mkdir($this->logDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDir)) {
            foreach (glob($this->logDir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($this->logDir);
        }
    }

    public function test_writes_one_jsonl_per_log_call(): void
    {
        $logger = new CronLogger($this->logDir, 'demo:cmd', new \DateTimeImmutable('2026-05-15T03:00:00'));
        $logger->info(['tenant' => 'daems', 'processed' => 42, 'created' => 5]);
        $logger->info(['summary' => true, 'total_created' => 5]);

        $file = $this->logDir . '/demo-cmd-2026-05-15.log';
        $this->assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(2, $lines);
        $first = json_decode($lines[0], true);
        $this->assertSame('daems', $first['tenant']);
        $this->assertSame(42, $first['processed']);
        $this->assertArrayHasKey('ts', $first);
    }

    public function test_error_writes_to_stderr_marker(): void
    {
        $logger = new CronLogger($this->logDir, 'demo:cmd', new \DateTimeImmutable('2026-05-15T03:00:00'));
        $logger->error(['tenant' => 'daems', 'message' => 'boom']);

        $file = $this->logDir . '/demo-cmd-2026-05-15.log';
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        $this->assertSame('error', $row['level']);
    }
}
