<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\LockManager;
use PHPUnit\Framework\TestCase;

final class LockManagerTest extends TestCase
{
    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/lockmgr-test-' . uniqid();
        mkdir($this->lockDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->lockDir)) {
            foreach (glob($this->lockDir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($this->lockDir);
        }
    }

    public function test_acquire_succeeds_when_no_other_holder(): void
    {
        $mgr = new LockManager($this->lockDir);
        $this->assertTrue($mgr->acquire('cmd-a'));
        $mgr->release('cmd-a');
    }

    public function test_acquire_fails_when_already_held_by_other_process(): void
    {
        // Simulate "other holder" by opening the file ourselves with LOCK_EX|LOCK_NB.
        $lockFile = $this->lockDir . '/cmd-b.lock';
        $fp = fopen($lockFile, 'c');
        $this->assertNotFalse($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        $mgr = new LockManager($this->lockDir);
        $this->assertFalse($mgr->acquire('cmd-b'));

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function test_release_allows_re_acquire(): void
    {
        $mgr = new LockManager($this->lockDir);
        $mgr->acquire('cmd-c');
        $mgr->release('cmd-c');
        $this->assertTrue($mgr->acquire('cmd-c'));
        $mgr->release('cmd-c');
    }
}
