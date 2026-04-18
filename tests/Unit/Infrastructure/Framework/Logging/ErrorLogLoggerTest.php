<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Logging;

use Daems\Infrastructure\Framework\Logging\ErrorLogLogger;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorLogLoggerTest extends TestCase
{
    private string $tmpfile;
    private string $originalLog;

    protected function setUp(): void
    {
        $this->tmpfile = (string) tempnam(sys_get_temp_dir(), 'daems-log-');
        $this->originalLog = (string) ini_get('error_log');
        ini_set('error_log', $this->tmpfile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalLog);
        if (file_exists($this->tmpfile)) {
            unlink($this->tmpfile);
        }
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, new ErrorLogLogger());
    }

    public function testErrorWritesToErrorLog(): void
    {
        (new ErrorLogLogger())->error('boom', ['k' => 'v']);
        $contents = (string) file_get_contents($this->tmpfile);
        $this->assertStringContainsString('boom', $contents);
        $this->assertStringContainsString('"k":"v"', $contents);
    }

    public function testErrorSerialisesException(): void
    {
        (new ErrorLogLogger())->error('failed', ['exception' => new RuntimeException('inner')]);
        $contents = (string) file_get_contents($this->tmpfile);
        $this->assertStringContainsString('RuntimeException', $contents);
        $this->assertStringContainsString('inner', $contents);
    }
}
