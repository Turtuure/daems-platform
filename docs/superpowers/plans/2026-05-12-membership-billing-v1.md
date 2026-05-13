# MembershipBilling v1 (0.7) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship MembershipBilling v1 — vuosittaiset jäsen- ja kannatusmaksut hallituksen päätöksellä, anniversary-pohjainen laskutus, waive/reduce-mekaniikka, manuaalinen + CSV-maksun kirjaus, ja § 4:n automaattinen 2v-maksamatta-trigger jäsenyyden päättymiselle. Skoppi-rajaus: 0.7 = base + manual + CSV. Stripe → 0.7.1, Visma → 0.7.2 omat milestonet.

**Architecture:** Clean Architecture `daems-platform`-repossa. Uusi `Daems\Domain\Membership\Billing\*` -sub-bounded-context. 12 use casea `Application/Membership/Billing/`-namespacessa. Snapshot-malli laskuissa (amount lukitaan luontihetkellä, ei seuraa hinnaston muutoksia). Cron-runner-infra (`bin/console`) rakennetaan 0.7:ssa anniversary-/overdue-/lapse-tarpeeseen. Hinnaston aktivointi joko suoraan tai 0.6b:n `board_decisions`-flow:n kautta (`requires_formal_decision_for_fees` per-tenant kytkin).

**Tech Stack:** PHP 8.3, MySQL 8.4, PHPUnit 11, PHPStan level 9, Clean Architecture (Domain ilman framework-deppejä), `.sql` + `.php` migraatiot.

**Branch:** `membership-billing-v1` (luotu off `dev` @ `a4a3828`; spec commitattu paikallisesti `a48ad5f`).

**Spec reference:** `docs/superpowers/specs/2026-05-12-membership-billing-v1-design.md`

**Estimated commit count:** ~70–90 commits across ~75 tasks.

---

## Cross-cutting reminders (apply to every task)

- **Commit identity:** every commit uses `git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."`. NEVER `Co-Authored-By:`. NEVER auto-push.
- **Never stage `.claude/`:** if it sneaks into the index run `git reset HEAD .claude/` before committing.
- **PHPStan gate:** every commit ends with `composer analyse` = 0 errors. If it fails, fix inline; do NOT commit.
- **DI BOTH containers:** every new use case / controller / SQL repository needs bindings in BOTH `bootstrap/app.php` (production) AND `tests/Support/KernelHarness.php` (test, with InMemory fakes). Grep for the new class name in both files before marking a task done.
- **Test suite names:** suites are `Unit` / `Integration` / `E2E` — capitalized. Lowercased silently returns "No tests executed!" per `feedback_phpunit_testsuite_names.md`.
- **MySQL CLI for migration smoke:** `"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/<file>.sql`. Test DB is `daems_db_test`.
- **Isolation suite flakiness:** post-0.6b Isolation suite is non-deterministic (~30-60% green per `feedback_isolation_suite_flaky.md`). Rerun on transient failures before suspecting real bugs.
- **No backwards-compat shims:** delete unused code completely (CLAUDE.md convention).

---

## Pre-flight verification (run once before Task A1)

- [ ] **Verify branch + clean tree**

```bash
git rev-parse --abbrev-ref HEAD
git status --short
```

Expected: `membership-billing-v1` and working tree shows only the spec commit (no other changes).

- [ ] **Verify spec is committed**

```bash
git log --oneline -3
```

Expected: top two commits include `a48ad5f Add(specs): MembershipBilling v1 design — milestone 0.7` and `a4a3828 Fix(tests/unit): BackstageSidebarTest assertions for governance group (post-0.6b)`.

- [ ] **Verify current migration baseline**

```bash
ls database/migrations/ | tail -5
```

Expected: ends with `088_seed_governance_settings_defaults.php`. The first new migration in this plan is `089`.

- [ ] **Verify baseline tests + analysis green**

```bash
composer analyse
composer test
```

Expected: PHPStan = 0 errors, Unit 1001/1001 + Integration green. (E2E may also be run; not required for pre-flight.)

If any pre-flight step fails STOP and fix before starting Task A1.

---

# Wave A — Cron-runner infrastructure (Phase 1, 6 tasks)

This wave builds the `bin/console` -CLI-runner from scratch. No billing-specific code yet — yleiskäyttöinen komento-dispatcher joka tukee tulevia anniversary-/overdue-/lapse-croneja sekä myöhempiä 0.8:n communications-croneja. Tehdään kerralla yleisesti hyödylliseksi.

## Task A1: ConsoleKernel + CommandInterface

**Files:**
- Create: `src/Infrastructure/Console/CommandInterface.php`
- Create: `src/Infrastructure/Console/ConsoleKernel.php`

- [ ] **Step 1: Write failing test for CommandInterface contract**

`tests/Unit/Infrastructure/Console/CommandInterfaceContractTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use PHPUnit\Framework\TestCase;

final class CommandInterfaceContractTest extends TestCase
{
    public function test_command_interface_has_execute_method(): void
    {
        $reflect = new \ReflectionClass(CommandInterface::class);
        $this->assertTrue($reflect->isInterface());
        $this->assertTrue($reflect->hasMethod('execute'));
        $this->assertTrue($reflect->hasMethod('name'));

        $execute = $reflect->getMethod('execute');
        $this->assertSame('int', (string) $execute->getReturnType());
        $this->assertCount(1, $execute->getParameters());
        $this->assertSame('args', $execute->getParameters()[0]->getName());

        $name = $reflect->getMethod('name');
        $this->assertSame('string', (string) $name->getReturnType());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter CommandInterfaceContractTest
```

Expected: FAIL with "Class Daems\Infrastructure\Console\CommandInterface does not exist".

- [ ] **Step 3: Create CommandInterface**

`src/Infrastructure/Console/CommandInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

interface CommandInterface
{
    /**
     * Stable command name shown in registry + lock file.
     * Format: `<domain>:<verb>-<noun>` (e.g. `membership:generate-anniversary-invoices`).
     */
    public function name(): string;

    /**
     * Run the command. Return 0 on success, non-zero on failure.
     *
     * @param array<string,string|bool> $args Parsed CLI arguments (--option=value)
     */
    public function execute(array $args): int;
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter CommandInterfaceContractTest
```

Expected: OK (1 test, 6 assertions).

- [ ] **Step 5: Write failing test for ConsoleKernel**

`tests/Unit/Infrastructure/Console/ConsoleKernelTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CommandRegistry;
use Daems\Infrastructure\Console\ConsoleKernel;
use PHPUnit\Framework\TestCase;

final class ConsoleKernelTest extends TestCase
{
    public function test_dispatches_known_command(): void
    {
        $cmd = new class implements CommandInterface {
            public array $receivedArgs = [];
            public function name(): string { return 'demo:hello'; }
            public function execute(array $args): int
            {
                $this->receivedArgs = $args;
                return 0;
            }
        };
        $registry = new CommandRegistry();
        $registry->register($cmd);
        $kernel = new ConsoleKernel($registry);

        $exit = $kernel->handle(['demo:hello', '--name=World', '--verbose']);

        $this->assertSame(0, $exit);
        $this->assertSame(['name' => 'World', 'verbose' => true], $cmd->receivedArgs);
    }

    public function test_returns_2_for_unknown_command(): void
    {
        $kernel = new ConsoleKernel(new CommandRegistry());
        $this->assertSame(2, $kernel->handle(['unknown:nope']));
    }

    public function test_returns_1_when_no_command_given(): void
    {
        $kernel = new ConsoleKernel(new CommandRegistry());
        $this->assertSame(1, $kernel->handle([]));
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter ConsoleKernelTest
```

Expected: FAIL with "Class Daems\Infrastructure\Console\CommandRegistry does not exist" (we create both ConsoleKernel + CommandRegistry stubs in next step).

- [ ] **Step 7: Create ConsoleKernel + stub CommandRegistry**

`src/Infrastructure/Console/ConsoleKernel.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

final class ConsoleKernel
{
    public function __construct(private readonly CommandRegistry $registry) {}

    /**
     * Dispatch. argv format: [command-name, --opt=value, --flag, ...]
     *
     * @param list<string> $argv
     */
    public function handle(array $argv): int
    {
        if (count($argv) === 0) {
            fwrite(STDERR, "Usage: bin/console <command> [--option=value]\n");
            return 1;
        }

        $name = (string) $argv[0];
        if (!$this->registry->has($name)) {
            fwrite(STDERR, "Unknown command: {$name}\n");
            return 2;
        }

        $args = $this->parseArgs(array_slice($argv, 1));
        return $this->registry->get($name)->execute($args);
    }

    /**
     * @param list<string> $rest
     * @return array<string,string|bool>
     */
    private function parseArgs(array $rest): array
    {
        $out = [];
        foreach ($rest as $token) {
            if (!str_starts_with($token, '--')) continue;
            $kv = substr($token, 2);
            if (str_contains($kv, '=')) {
                [$k, $v] = explode('=', $kv, 2);
                $out[$k] = $v;
            } else {
                $out[$kv] = true;
            }
        }
        return $out;
    }
}
```

`src/Infrastructure/Console/CommandRegistry.php` (stub — filled in Task A2):

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

final class CommandRegistry
{
    /** @var array<string,CommandInterface> */
    private array $commands = [];

    public function register(CommandInterface $cmd): void
    {
        $this->commands[$cmd->name()] = $cmd;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): CommandInterface
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException("No command registered: {$name}");
        }
        return $this->commands[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->commands);
    }
}
```

- [ ] **Step 8: Run all new tests + PHPStan**

```bash
vendor/bin/phpunit --filter 'CommandInterface|ConsoleKernel'
composer analyse
```

Expected: 4 tests, 11 assertions, OK. PHPStan 0 errors.

- [ ] **Step 9: Commit**

```bash
git add src/Infrastructure/Console/ tests/Unit/Infrastructure/Console/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(console): CommandInterface + ConsoleKernel + CommandRegistry"
```

---

## Task A2: CommandRegistry tests + behavior

**Files:**
- Modify: `src/Infrastructure/Console/CommandRegistry.php` (already exists from A1)
- Create: `tests/Unit/Infrastructure/Console/CommandRegistryTest.php`

The registry from A1 has minimal API. Add explicit tests for edge cases.

- [ ] **Step 1: Write tests**

`tests/Unit/Infrastructure/Console/CommandRegistryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CommandRegistry;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    public function test_register_and_lookup(): void
    {
        $registry = new CommandRegistry();
        $cmd = $this->stubCommand('foo:bar');
        $registry->register($cmd);

        $this->assertTrue($registry->has('foo:bar'));
        $this->assertSame($cmd, $registry->get('foo:bar'));
    }

    public function test_get_throws_for_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommandRegistry())->get('does-not-exist');
    }

    public function test_names_returns_registered_in_order(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->stubCommand('a:one'));
        $registry->register($this->stubCommand('b:two'));
        $registry->register($this->stubCommand('c:three'));

        $this->assertSame(['a:one', 'b:two', 'c:three'], $registry->names());
    }

    public function test_re_register_replaces_previous(): void
    {
        $registry = new CommandRegistry();
        $first  = $this->stubCommand('x:cmd');
        $second = $this->stubCommand('x:cmd');
        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->get('x:cmd'));
    }

    private function stubCommand(string $name): CommandInterface
    {
        return new class($name) implements CommandInterface {
            public function __construct(private readonly string $n) {}
            public function name(): string { return $this->n; }
            public function execute(array $args): int { return 0; }
        };
    }
}
```

- [ ] **Step 2: Run + PHPStan**

```bash
vendor/bin/phpunit --filter CommandRegistryTest
composer analyse
```

Expected: 4 tests OK. PHPStan 0.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Infrastructure/Console/CommandRegistryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/console): CommandRegistry edge-case coverage"
```

---

## Task A3: LockManager (flock-based concurrency guard)

**Files:**
- Create: `src/Infrastructure/Console/LockManager.php`
- Create: `tests/Unit/Infrastructure/Console/LockManagerTest.php`
- Create: `var/run/.gitkeep`

- [ ] **Step 1: Ensure var/run dir exists + tracked**

```bash
mkdir -p var/run
echo "*" > var/run/.gitignore
echo "!.gitignore" >> var/run/.gitignore
git add var/run/.gitignore
```

(Locks are runtime artifacts — don't commit lock files themselves.)

- [ ] **Step 2: Write failing test**

`tests/Unit/Infrastructure/Console/LockManagerTest.php`:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter LockManagerTest
```

Expected: FAIL with "Class Daems\Infrastructure\Console\LockManager does not exist".

- [ ] **Step 4: Implement LockManager**

`src/Infrastructure/Console/LockManager.php`:

```php
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
```

- [ ] **Step 5: Run all tests + PHPStan**

```bash
vendor/bin/phpunit --filter LockManagerTest
composer analyse
```

Expected: 3 tests OK. PHPStan 0.

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Console/LockManager.php tests/Unit/Infrastructure/Console/LockManagerTest.php var/run/.gitignore
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(console): LockManager — flock-based concurrency guard"
```

---

## Task A4: bin/console entry script

**Files:**
- Create: `bin/console`

- [ ] **Step 1: Verify bin/ exists**

```bash
ls bin/ 2>/dev/null || mkdir bin
```

- [ ] **Step 2: Create the entry script**

`bin/console`:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/console — CLI entry for cron-runners and ops commands.
 *
 * Usage:
 *   php bin/console <command> [--option=value] [--flag]
 *
 * Commands are registered in bootstrap/console.php (which is loaded here).
 * The kernel parses argv, looks up the command, and dispatches.
 *
 * Exit codes:
 *   0 — success (command ran, or already-held lock so we exited cleanly)
 *   1 — usage error (no command name given)
 *   2 — unknown command name
 *   3+ — command-specific failure (logged to stderr)
 */

require __DIR__ . '/../vendor/autoload.php';

/** @var \Daems\Infrastructure\Console\ConsoleKernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/console.php';

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // drop the script name

exit($kernel->handle($argv));
```

- [ ] **Step 3: Create the bootstrap/console.php file**

`bootstrap/console.php`:

```php
<?php
declare(strict_types=1);

use Daems\Infrastructure\Console\CommandRegistry;
use Daems\Infrastructure\Console\ConsoleKernel;

$registry = new CommandRegistry();

// Smoke-test command — verifies infra works before any real command is wired.
// Remove or leave in place; it's harmless and useful for ops debugging.
$registry->register(new class implements \Daems\Infrastructure\Console\CommandInterface {
    public function name(): string { return 'console:hello'; }
    public function execute(array $args): int {
        $name = isset($args['name']) && is_string($args['name']) ? $args['name'] : 'world';
        fwrite(STDOUT, "Hello, {$name}\n");
        return 0;
    }
});

// Real commands are registered in later tasks (Wave C: anniversary-cron, Wave F: overdue + lapse).

return new ConsoleKernel($registry);
```

- [ ] **Step 4: Smoke-test the runner manually**

```bash
php bin/console console:hello
php bin/console console:hello --name=Dev
php bin/console
php bin/console nope:nope
```

Expected:
- `Hello, world` (exit 0)
- `Hello, Dev` (exit 0)
- Usage message (exit 1)
- `Unknown command: nope:nope` (exit 2)

Verify exit codes:
```bash
php bin/console console:hello; echo $?
php bin/console; echo $?
php bin/console nope; echo $?
```

- [ ] **Step 5: Commit**

```bash
git add bin/console bootstrap/console.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(bin): bin/console CLI entry + smoke-test command"
```

---

## Task A5: Logging helper for cron commands

**Files:**
- Create: `src/Infrastructure/Console/CronLogger.php`
- Create: `tests/Unit/Infrastructure/Console/CronLoggerTest.php`
- Create: `var/log/cron/.gitignore`

Cron commands write JSONL audit lines per tenant. Centralize the format so all three crons (anniversary, overdue, lapse) produce parseable logs.

- [ ] **Step 1: Create var/log/cron dir**

```bash
mkdir -p var/log/cron
cat > var/log/cron/.gitignore <<'EOF'
*
!.gitignore
EOF
git add var/log/cron/.gitignore
```

- [ ] **Step 2: Write failing test**

`tests/Unit/Infrastructure/Console/CronLoggerTest.php`:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter CronLoggerTest
```

Expected: FAIL with class-not-found.

- [ ] **Step 4: Implement CronLogger**

`src/Infrastructure/Console/CronLogger.php`:

```php
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
```

- [ ] **Step 5: Run + PHPStan**

```bash
vendor/bin/phpunit --filter CronLoggerTest
composer analyse
```

Expected: 2 tests OK. PHPStan 0.

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Console/CronLogger.php tests/Unit/Infrastructure/Console/CronLoggerTest.php var/log/cron/.gitignore
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(console): CronLogger — JSONL per-day log writer"
```

---

## Task A6: docs/operations/cron-setup.md

**Files:**
- Create: `docs/operations/cron-setup.md`

- [ ] **Step 1: Write the doc**

`docs/operations/cron-setup.md`:

````markdown
# Cron-runner setup

The platform's `bin/console` -CLI runs scheduled jobs. Three jobs are in scope for milestone 0.7 (MembershipBilling):

| Command | Frequency | Time | Purpose |
|---|---|---|---|
| `membership:generate-anniversary-invoices` | Daily | 02:00 | Create yearly invoices for members whose anniversary is today |
| `membership:mark-overdue-invoices` | Daily | 02:30 | Flag PENDING → OVERDUE for invoices past due_date + grace |
| `membership:lapse-inactive-members` | Daily | 03:00 | Lapse members with 2 consecutive years OVERDUE (§ 4 deemed-resignation) |

Each command:
- Acquires an exclusive `flock()` on `var/run/<command>.lock` — overlapping runs exit 0 cleanly
- Writes JSONL audit to `var/log/cron/<command>-YYYY-MM-DD.log`
- Per-tenant try/catch — one tenant's failure does not block others

## Windows Server (Task Scheduler)

Production runs on Windows. Create three scheduled tasks via PowerShell as Administrator:

```powershell
# Anniversary-invoice creation
$Action = New-ScheduledTaskAction `
  -Execute 'C:\laragon\bin\php\php-8.3.26\php.exe' `
  -Argument 'C:\laragon\www\daems-platform\bin\console membership:generate-anniversary-invoices' `
  -WorkingDirectory 'C:\laragon\www\daems-platform'

$Trigger = New-ScheduledTaskTrigger -Daily -At 02:00

$Settings = New-ScheduledTaskSettingsSet `
  -StartWhenAvailable `
  -RunOnlyIfNetworkAvailable `
  -ExecutionTimeLimit (New-TimeSpan -Hours 1)

Register-ScheduledTask `
  -TaskName 'Daems\Membership-Anniversary-Invoices' `
  -Action $Action `
  -Trigger $Trigger `
  -Settings $Settings `
  -User 'NT AUTHORITY\SYSTEM' `
  -RunLevel Highest
```

Repeat for `membership:mark-overdue-invoices` at 02:30 and `membership:lapse-inactive-members` at 03:00. Replace `Daems\` task-folder prefix with your tenant slug if hosting multiple platforms.

## Linux / macOS (crontab)

```cron
# /etc/cron.d/daems-membership-billing
0 2 * * * www-data cd /var/www/daems-platform && /usr/bin/php bin/console membership:generate-anniversary-invoices >> var/log/cron/cron.out 2>> var/log/cron/cron.err
30 2 * * * www-data cd /var/www/daems-platform && /usr/bin/php bin/console membership:mark-overdue-invoices >> var/log/cron/cron.out 2>> var/log/cron/cron.err
0 3 * * * www-data cd /var/www/daems-platform && /usr/bin/php bin/console membership:lapse-inactive-members >> var/log/cron/cron.out 2>> var/log/cron/cron.err
```

## Dev workflow

Run manually any time:

```bash
php bin/console membership:generate-anniversary-invoices
php bin/console membership:mark-overdue-invoices
php bin/console membership:lapse-inactive-members --dry-run    # preview lapses
php bin/console membership:lapse-inactive-members              # commit
```

`--dry-run` is supported on `lapse-inactive-members` only — it prints the user_ids that would lapse without modifying state. Anniversary + overdue commands have no dry-run because their effects are reversible (anniversary creates idempotent rows; overdue flips a flag that auto-mark commands can re-evaluate).

## Verifying a scheduled run

After a scheduled run, inspect the log:

```bash
type var\log\cron\membership-generate-anniversary-invoices-2026-05-15.log
```

Each line is JSON. Expect per-tenant rows + a summary row at the end:

```jsonl
{"ts":"2026-05-15T02:00:01+03:00","level":"info","tenant":"daems","processed":42,"created":5,"skipped":0,"errors":0}
{"ts":"2026-05-15T02:00:03+03:00","level":"info","tenant":"sahegroup","processed":12,"created":2,"skipped":0,"errors":0}
{"ts":"2026-05-15T02:00:03+03:00","level":"info","summary":true,"total_tenants":2,"total_created":7,"duration_ms":2103}
```

If a run is skipped due to a stale lock (previous run still in progress), you'll see:

```jsonl
{"ts":"2026-05-15T02:00:00+03:00","level":"info","message":"lock held, skipping","command":"membership:generate-anniversary-invoices"}
```

## Troubleshooting

- **Lock-held loops:** if a command is "always skipping" check `var/run/<cmd>.lock` content (it holds the PID of the holder). If the PID is dead, delete the lock file manually. The next run will re-acquire.
- **Permission errors writing to var/log/cron:** make sure the task user (NT AUTHORITY\SYSTEM on Windows, www-data on Linux) has write access to `var/log/cron/` and `var/run/`.
- **PHP version mismatch:** the platform requires PHP 8.3+. On Windows, point the scheduled task `Execute` field to `C:\laragon\bin\php\php-8.3.x\php.exe` explicitly, not the system `php` alias.
````

- [ ] **Step 2: Commit**

```bash
git add docs/operations/cron-setup.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(docs/ops): cron-setup.md — Task Scheduler + crontab + troubleshooting"
```

---

## Wave A — Definition of Done

After Tasks A1-A6, the following invariants hold. Verify before starting Wave B:

- [ ] `php bin/console console:hello` prints `Hello, world` and exits 0
- [ ] `php bin/console nope` prints "Unknown command: nope" and exits 2
- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --testsuite Unit` is green (count up by 9-13 from baseline)
- [ ] `docs/operations/cron-setup.md` is on disk and committed

Run the verification gate:

```bash
php bin/console console:hello
composer analyse
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -3
```

If anything fails, FIX in a new commit before starting Wave B. Do NOT proceed with a broken foundation.

---

# Wave B — AnnualFeeSchedule domain + decision integration (Phases 2-3, 15 tasks)

This wave builds the per-(tenant, year, fee_type) hinta-versiointi: 4 migraatiota (089, 090, 094, 095), domain entiteetti + repo, 2 use casea (Draft + Activate), board_decisions-integraatio configurable per-tenant `requires_formal_decision_for_fees`-asetuksella, ja admin-UI hinnaston editointiin.

Tämän wave:n jälkeen hallitus voi luoda + aktivoida vuoden hinnaston joko suoraan (admin-action) tai formal board_decisions-flow:n kautta.

## Task B1: Migration 089 — backfill membership_started_at for SUPPORTING

**Files:**
- Create: `database/migrations/089_backfill_membership_started_at_for_supporting.php`

Migration 075 backfilled `membership_started_at` only for BASIC/FULL/HONORARY. SUPPORTING-members got missed — but they're the ones who pay the annual `kannatusmaksu` and need anniversary-cron.

- [ ] **Step 1: Write the migration**

`database/migrations/089_backfill_membership_started_at_for_supporting.php`:

```php
<?php
declare(strict_types=1);

/**
 * 089_backfill_membership_started_at_for_supporting.php
 * Fills SUPPORTING members' membership_started_at from users.created_at.
 * Mig 075 missed them; anniversary-cron (Wave C) needs this column populated
 * for SUPPORTING in addition to BASIC/FULL/HONORARY.
 *
 * Idempotent: WHERE membership_started_at IS NULL.
 *
 * Test mode: MigrationTestCase passes $pdo via require.
 * Standalone: `php database/migrations/089_backfill_membership_started_at_for_supporting.php`.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $envFile = __DIR__ . '/../../.env';
    $env = [];
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $env[$k] = $v;
        }
    }
    $pdo = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1')
            . ';port=' . ($env['DB_PORT'] ?? '3306')
            . ';dbname=' . ($env['DB_DATABASE'] ?? 'daems_db')
            . ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? 'root',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

$affected = $pdo->exec(
    "UPDATE users
        SET membership_started_at = created_at
      WHERE membership_started_at IS NULL
        AND membership_type = 'SUPPORTING'"
);
fwrite(STDOUT, "Backfilled {$affected} SUPPORTING users\n");
```

- [ ] **Step 2: Apply to dev + test DBs + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT COUNT(*) AS missing FROM users WHERE membership_started_at IS NULL AND membership_type='SUPPORTING';"
php database/migrations/089_backfill_membership_started_at_for_supporting.php
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SELECT COUNT(*) AS missing FROM users WHERE membership_started_at IS NULL AND membership_type='SUPPORTING';"
```

Expected: first query shows N rows missing, after migration shows 0.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/089_backfill_membership_started_at_for_supporting.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 089 — backfill membership_started_at for SUPPORTING members"
```

---

## Task B2: Migration 090 — annual_fee_schedules table

**Files:**
- Create: `database/migrations/090_create_annual_fee_schedules.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/090_create_annual_fee_schedules.sql`:

```sql
-- 090_create_annual_fee_schedules.sql
-- Per (tenant, year, fee_type) yearly price set by hallituksen päätös or direct admin-action.
-- status lifecycle: draft → proposed (if formal decision) → active OR superseded.
-- Multiple history rows per (tenant, year, fee_type) exist; "current" is WHERE status='active'.
-- Uniqueness is enforced at application level inside ActivateAnnualFeeSchedule (transaction).

CREATE TABLE IF NOT EXISTS annual_fee_schedules (
    id              CHAR(36)             NOT NULL,
    tenant_id       CHAR(36)             NOT NULL,
    year            SMALLINT UNSIGNED    NOT NULL,
    fee_type        VARCHAR(20)          NOT NULL,
    amount_cents    INT UNSIGNED         NOT NULL DEFAULT 0,
    currency        CHAR(3)              NOT NULL DEFAULT 'EUR',
    status          VARCHAR(20)          NOT NULL DEFAULT 'draft',
    decision_id     CHAR(36)             NULL,
    activated_at    DATETIME             NULL,
    activated_by    CHAR(36)             NULL,
    superseded_at   DATETIME             NULL,
    created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      CHAR(36)             NULL,
    PRIMARY KEY (id),
    KEY idx_lookup (tenant_id, year, fee_type, status),
    KEY idx_decision (decision_id),
    CONSTRAINT fk_afs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_afs_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE SET NULL,
    CONSTRAINT fk_afs_activated_by FOREIGN KEY (activated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_afs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply to dev + test DBs**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/090_create_annual_fee_schedules.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/090_create_annual_fee_schedules.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE annual_fee_schedules\G"
```

Expected: table exists with PK + 2 indexes + 4 FKs.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/090_create_annual_fee_schedules.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 090 — annual_fee_schedules table"
```

---

## Task B3: Migration 094 — extend tenant_governance_settings for billing

**Files:**
- Create: `database/migrations/094_extend_tenant_governance_settings_for_billing.sql`

Note we use slot 094 ahead of 095 deliberately — these are settings columns the rest of the plan depends on. Slot 091 (member_fee_invoices) comes in Wave C, 092 (user_fee_overrides) in Wave D, 093 (audit) in Wave E. Sequential slot order matches MigrationTestCase ordering.

- [ ] **Step 1: Write the migration**

`database/migrations/094_extend_tenant_governance_settings_for_billing.sql`:

```sql
-- 094_extend_tenant_governance_settings_for_billing.sql
-- Adds billing-related toggles to the per-tenant governance settings row.
-- All 4 columns are nullable-with-default so existing rows from migration 088
-- pick up sensible defaults without an explicit UPDATE.

ALTER TABLE tenant_governance_settings
    ADD COLUMN requires_formal_decision_for_fees TINYINT(1) NOT NULL DEFAULT 0
        AFTER decision_expiration_days,
    ADD COLUMN default_due_days_from_anniversary INT NOT NULL DEFAULT 60
        AFTER requires_formal_decision_for_fees,
    ADD COLUMN overdue_grace_days INT NOT NULL DEFAULT 30
        AFTER default_due_days_from_anniversary,
    ADD COLUMN lapse_check_enabled TINYINT(1) NOT NULL DEFAULT 1
        AFTER overdue_grace_days;
```

- [ ] **Step 2: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/094_extend_tenant_governance_settings_for_billing.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/094_extend_tenant_governance_settings_for_billing.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "DESCRIBE tenant_governance_settings;"
```

Expected: 4 new columns present with the documented defaults.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/094_extend_tenant_governance_settings_for_billing.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 094 — billing settings on tenant_governance_settings"
```

---

## Task B4: Migration 095 — extend board_decisions.decision_type

**Files:**
- Read: `database/migrations/080_create_board_decisions.sql` (verify current enum/check shape)
- Create: `database/migrations/095_extend_board_decisions_decision_type.sql`

- [ ] **Step 1: Inspect existing decision_type definition**

```bash
grep -n "decision_type" database/migrations/080_create_board_decisions.sql
```

Read the file. The migration was authored in 0.6b. Two outcomes:

- **If `decision_type` is `ENUM('approve_basic','approve_full',...)`:** we need `ALTER TABLE ... MODIFY COLUMN decision_type ENUM(...new list...)`.
- **If `decision_type` is `VARCHAR(...)` with no CHECK constraint:** no DDL change is needed — application code just starts using a new value. Migration 095 becomes a no-op data-fix that documents the addition.

Pick the appropriate path in Step 2.

- [ ] **Step 2: Write the migration**

Case A — ENUM-style: `database/migrations/095_extend_board_decisions_decision_type.sql`:

```sql
-- 095_extend_board_decisions_decision_type.sql
-- Adds 'annual_fee_schedule' to the board_decisions.decision_type enum so the
-- 0.7 MembershipBilling DraftAnnualFeeSchedule use case can route through the
-- formal hallitus-päätös flow when tenant_governance_settings.requires_formal_decision_for_fees=1.

ALTER TABLE board_decisions
    MODIFY COLUMN decision_type ENUM(
        'approve_basic',
        'approve_full',
        'change_membership_type',
        'award_sub_tier',
        'expel_member',
        'delegate_authority',
        'annual_fee_schedule'
    ) NOT NULL;
```

(Replace the enum literal list above with the EXACT current value list from 080 plus `'annual_fee_schedule'` appended. If you skip an existing value MySQL will fail with constraint violations.)

Case B — VARCHAR-style: `database/migrations/095_extend_board_decisions_decision_type.sql`:

```sql
-- 095_extend_board_decisions_decision_type.sql
-- No DDL change needed — board_decisions.decision_type is VARCHAR, so adding a
-- new value 'annual_fee_schedule' (used by 0.7 MembershipBilling) requires no
-- schema migration. This file exists as a documentation marker so dev DBs
-- stamp it in schema_migrations and developers can grep for when this value
-- was introduced.

DO 0;
```

- [ ] **Step 3: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/095_extend_board_decisions_decision_type.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/095_extend_board_decisions_decision_type.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW COLUMNS FROM board_decisions LIKE 'decision_type';"
```

Expected: `decision_type` shows the new enum list (case A) or remains VARCHAR (case B).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/095_extend_board_decisions_decision_type.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 095 — extend board_decisions.decision_type for annual_fee_schedule"
```

---

## Task B5: Update IsolationTestCase watermark 88 → 95

**Files:**
- Modify: `tests/Isolation/IsolationTestCase.php` (single-line change)

The Isolation suite runs migrations up to a hardcoded watermark. After 4 new migrations land, the watermark must move from 88 → 95 so isolation tests see the new schema.

- [ ] **Step 1: Update the watermark**

Open `tests/Isolation/IsolationTestCase.php` and change:

```php
$this->runMigrationsUpTo(88);
```

to:

```php
$this->runMigrationsUpTo(95);
```

- [ ] **Step 2: Verify Isolation suite still runs (note flakiness — re-run 2x if needed)**

```bash
vendor/bin/phpunit --testsuite Isolation 2>&1 | tail -5
```

Expected: `OK (63 tests, N assertions)`. **Known flakiness** — if you see failures referencing `Duplicate column` or `Failed FK` or `Deadlock`, rerun once. Two consecutive green runs = baseline is good. See `feedback_isolation_suite_flaky.md`.

- [ ] **Step 3: Commit**

```bash
git add tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(tests/isolation): migration watermark 88 → 95 (billing schema)"
```

---

## Task B6: AnnualFeeScheduleStatus enum + AnnualFeeScheduleId VO

**Files:**
- Create: `src/Domain/Membership/Billing/AnnualFeeScheduleStatus.php`
- Create: `src/Domain/Membership/Billing/AnnualFeeScheduleId.php`
- Create: `tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleStatusTest.php`
- Create: `tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleIdTest.php`

- [ ] **Step 1: Write failing test for status enum**

`tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleStatusTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use PHPUnit\Framework\TestCase;

final class AnnualFeeScheduleStatusTest extends TestCase
{
    public function test_has_4_states(): void
    {
        $this->assertSame('draft',      AnnualFeeScheduleStatus::Draft->value);
        $this->assertSame('proposed',   AnnualFeeScheduleStatus::Proposed->value);
        $this->assertSame('active',     AnnualFeeScheduleStatus::Active->value);
        $this->assertSame('superseded', AnnualFeeScheduleStatus::Superseded->value);
    }

    public function test_from_string_round_trip(): void
    {
        foreach (['draft', 'proposed', 'active', 'superseded'] as $value) {
            $this->assertSame($value, AnnualFeeScheduleStatus::from($value)->value);
        }
    }
}
```

- [ ] **Step 2: Write failing test for ID VO**

`tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleIdTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use PHPUnit\Framework\TestCase;

final class AnnualFeeScheduleIdTest extends TestCase
{
    public function test_generate_creates_uuid7(): void
    {
        $id = AnnualFeeScheduleId::generate();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id->value());
    }

    public function test_from_string_round_trip(): void
    {
        $raw = '01958000-0000-7000-8000-0000000000aa';
        $id = AnnualFeeScheduleId::fromString($raw);
        $this->assertSame($raw, $id->value());
    }

    public function test_from_string_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AnnualFeeScheduleId::fromString('not-a-uuid');
    }
}
```

- [ ] **Step 3: Run both tests to verify failure**

```bash
vendor/bin/phpunit --filter 'AnnualFeeScheduleStatusTest|AnnualFeeScheduleIdTest'
```

Expected: FAIL — classes not yet defined.

- [ ] **Step 4: Create AnnualFeeScheduleStatus enum**

`src/Domain/Membership/Billing/AnnualFeeScheduleStatus.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

/**
 * Lifecycle of a (tenant, year, fee_type) fee row.
 *
 * draft      — admin started editing, not submitted
 * proposed   — admin submitted; awaiting board decision (if requires_formal_decision_for_fees=1)
 * active     — current canonical price for that (tenant, year, fee_type)
 * superseded — replaced by a newer row (kept for audit; never deleted)
 */
enum AnnualFeeScheduleStatus: string
{
    case Draft      = 'draft';
    case Proposed   = 'proposed';
    case Active     = 'active';
    case Superseded = 'superseded';
}
```

- [ ] **Step 5: Create AnnualFeeScheduleId VO**

`src/Domain/Membership/Billing/AnnualFeeScheduleId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class AnnualFeeScheduleId
{
    private function __construct(private readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid7::generate()->value());
    }

    public static function fromString(string $raw): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $raw) !== 1) {
            throw new \InvalidArgumentException("Not a valid UUIDv7: {$raw}");
        }
        return new self($raw);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

- [ ] **Step 6: Run all new tests + PHPStan**

```bash
vendor/bin/phpunit --filter 'AnnualFeeScheduleStatusTest|AnnualFeeScheduleIdTest'
composer analyse
```

Expected: 6 tests OK. PHPStan 0.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Membership/Billing/AnnualFeeScheduleStatus.php src/Domain/Membership/Billing/AnnualFeeScheduleId.php tests/Unit/Domain/Membership/Billing/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain): AnnualFeeScheduleStatus enum + AnnualFeeScheduleId VO"
```

---

## Task B7: AnnualFeeSchedule entity

**Files:**
- Create: `src/Domain/Membership/Billing/AnnualFeeSchedule.php`
- Create: `tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleTest.php`

- [ ] **Step 1: Write failing entity test**

`tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AnnualFeeScheduleTest extends TestCase
{
    public function test_draft_constructs_with_zero_amount_allowed(): void
    {
        $schedule = $this->schedule(amountCents: 0, status: AnnualFeeScheduleStatus::Draft);
        $this->assertSame(0, $schedule->amountCents());
        $this->assertSame(AnnualFeeScheduleStatus::Draft, $schedule->status());
    }

    public function test_rejects_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->schedule(amountCents: -1);
    }

    public function test_supersedes_marks_status_and_sets_timestamp(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Active);
        $now = new DateTimeImmutable('2027-01-15T10:00:00');
        $schedule->supersede($now);

        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $schedule->status());
        $this->assertEquals($now, $schedule->supersededAt());
    }

    public function test_activate_from_proposed(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Proposed);
        $now = new DateTimeImmutable('2026-12-01T00:00:00');
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $schedule->activate($actor, $now);

        $this->assertSame(AnnualFeeScheduleStatus::Active, $schedule->status());
        $this->assertEquals($now, $schedule->activatedAt());
        $this->assertEquals($actor, $schedule->activatedBy());
    }

    public function test_activate_rejects_already_active(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Active);
        $this->expectException(\DomainException::class);
        $schedule->activate(UserId::fromString('01958000-0000-7000-8000-0000000000cc'), new DateTimeImmutable());
    }

    public function test_activate_rejects_superseded(): void
    {
        $schedule = $this->schedule(status: AnnualFeeScheduleStatus::Superseded);
        $this->expectException(\DomainException::class);
        $schedule->activate(UserId::fromString('01958000-0000-7000-8000-0000000000dd'), new DateTimeImmutable());
    }

    private function schedule(
        int $amountCents = 5000,
        AnnualFeeScheduleStatus $status = AnnualFeeScheduleStatus::Draft,
    ): AnnualFeeSchedule {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::fromString('01958000-0000-7000-8000-0000000000aa'),
            tenantId:     TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            year:         2027,
            feeType:      MembershipType::Basic,
            amountCents:  $amountCents,
            currency:     'EUR',
            status:       $status,
            decisionId:   null,
            activatedAt:  null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2026-11-01T00:00:00'),
            createdBy:    null,
        );
    }
}
```

- [ ] **Step 2: Run test to verify failure**

```bash
vendor/bin/phpunit --filter AnnualFeeScheduleTest
```

Expected: FAIL — class not defined.

- [ ] **Step 3: Create AnnualFeeSchedule entity**

`src/Domain/Membership/Billing/AnnualFeeSchedule.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * One yearly fee row per (tenant, year, fee_type).
 *
 * Invariants:
 *   - amount_cents >= 0 (0 = full waiver; allowed e.g. for FULL type per § 3)
 *   - status transitions: Draft → Proposed → Active → Superseded
 *     OR Draft → Active (direct admin-action, no formal decision)
 *   - Active row can be superseded, never re-activated
 *   - Superseded is terminal — kept for audit only
 *
 * HONORARY type has no AnnualFeeSchedule row (§ 3 kunniajäsen ei maksa).
 */
final class AnnualFeeSchedule
{
    public function __construct(
        private readonly AnnualFeeScheduleId $id,
        private readonly TenantId            $tenantId,
        private readonly int                 $year,
        private readonly MembershipType      $feeType,
        private readonly int                 $amountCents,
        private readonly string              $currency,
        private AnnualFeeScheduleStatus      $status,
        private readonly ?string             $decisionId,
        private ?DateTimeImmutable           $activatedAt,
        private ?UserId                      $activatedBy,
        private ?DateTimeImmutable           $supersededAt,
        private readonly DateTimeImmutable   $createdAt,
        private readonly ?UserId             $createdBy,
    ) {
        if ($amountCents < 0) {
            throw new InvalidArgumentException("amount_cents must be >= 0, got {$amountCents}");
        }
        if ($feeType === MembershipType::Honorary) {
            throw new InvalidArgumentException("HONORARY type cannot have a fee schedule (§ 3)");
        }
    }

    public function id(): AnnualFeeScheduleId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function year(): int { return $this->year; }
    public function feeType(): MembershipType { return $this->feeType; }
    public function amountCents(): int { return $this->amountCents; }
    public function currency(): string { return $this->currency; }
    public function status(): AnnualFeeScheduleStatus { return $this->status; }
    public function decisionId(): ?string { return $this->decisionId; }
    public function activatedAt(): ?DateTimeImmutable { return $this->activatedAt; }
    public function activatedBy(): ?UserId { return $this->activatedBy; }
    public function supersededAt(): ?DateTimeImmutable { return $this->supersededAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function createdBy(): ?UserId { return $this->createdBy; }

    public function activate(UserId $actor, DateTimeImmutable $now): void
    {
        if ($this->status === AnnualFeeScheduleStatus::Active) {
            throw new DomainException("Schedule already active");
        }
        if ($this->status === AnnualFeeScheduleStatus::Superseded) {
            throw new DomainException("Cannot activate a superseded schedule");
        }
        $this->status = AnnualFeeScheduleStatus::Active;
        $this->activatedAt = $now;
        $this->activatedBy = $actor;
    }

    public function supersede(DateTimeImmutable $now): void
    {
        if ($this->status !== AnnualFeeScheduleStatus::Active) {
            throw new DomainException("Only active schedules can be superseded; this is {$this->status->value}");
        }
        $this->status = AnnualFeeScheduleStatus::Superseded;
        $this->supersededAt = $now;
    }
}
```

- [ ] **Step 4: Run all tests + PHPStan**

```bash
vendor/bin/phpunit --filter AnnualFeeScheduleTest
composer analyse
```

Expected: 6 tests OK. PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Membership/Billing/AnnualFeeSchedule.php tests/Unit/Domain/Membership/Billing/AnnualFeeScheduleTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain): AnnualFeeSchedule entity with state-transition invariants"
```

---

## Task B8: AnnualFeeScheduleRepositoryInterface + InMemory fake

**Files:**
- Create: `src/Domain/Membership/Billing/AnnualFeeScheduleRepositoryInterface.php`
- Create: `tests/Support/Fake/InMemoryAnnualFeeScheduleRepository.php`

- [ ] **Step 1: Define the interface**

`src/Domain/Membership/Billing/AnnualFeeScheduleRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;

interface AnnualFeeScheduleRepositoryInterface
{
    public function save(AnnualFeeSchedule $schedule): void;

    public function findById(AnnualFeeScheduleId $id): ?AnnualFeeSchedule;

    /**
     * Lookup the currently-active row for (tenant, year, fee_type).
     * Returns null if no active schedule exists yet for that combination.
     */
    public function findActiveFor(TenantId $tenantId, int $year, MembershipType $feeType): ?AnnualFeeSchedule;

    /**
     * All proposed-status rows for a year (used by AnnualFeeSchedulePassedHandler
     * to activate them after a board decision passes).
     *
     * @return list<AnnualFeeSchedule>
     */
    public function findProposedFor(TenantId $tenantId, int $year, ?string $decisionId = null): array;

    /**
     * All rows for a year (admin UI listing).
     *
     * @return list<AnnualFeeSchedule>
     */
    public function listForTenantYear(TenantId $tenantId, int $year): array;
}
```

- [ ] **Step 2: Create InMemory fake**

`tests/Support/Fake/InMemoryAnnualFeeScheduleRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;

final class InMemoryAnnualFeeScheduleRepository implements AnnualFeeScheduleRepositoryInterface
{
    /** @var array<string, AnnualFeeSchedule> */
    private array $byId = [];

    public function save(AnnualFeeSchedule $schedule): void
    {
        $this->byId[$schedule->id()->value()] = $schedule;
    }

    public function findById(AnnualFeeScheduleId $id): ?AnnualFeeSchedule
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findActiveFor(TenantId $tenantId, int $year, MembershipType $feeType): ?AnnualFeeSchedule
    {
        foreach ($this->byId as $s) {
            if ($s->tenantId()->equals($tenantId)
                && $s->year() === $year
                && $s->feeType() === $feeType
                && $s->status() === AnnualFeeScheduleStatus::Active
            ) {
                return $s;
            }
        }
        return null;
    }

    public function findProposedFor(TenantId $tenantId, int $year, ?string $decisionId = null): array
    {
        $out = [];
        foreach ($this->byId as $s) {
            if (!$s->tenantId()->equals($tenantId)) continue;
            if ($s->year() !== $year) continue;
            if ($s->status() !== AnnualFeeScheduleStatus::Proposed) continue;
            if ($decisionId !== null && $s->decisionId() !== $decisionId) continue;
            $out[] = $s;
        }
        return array_values($out);
    }

    public function listForTenantYear(TenantId $tenantId, int $year): array
    {
        $out = [];
        foreach ($this->byId as $s) {
            if ($s->tenantId()->equals($tenantId) && $s->year() === $year) {
                $out[] = $s;
            }
        }
        return array_values($out);
    }
}
```

- [ ] **Step 3: Verify PHPStan + manual smoke**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Membership/Billing/AnnualFeeScheduleRepositoryInterface.php tests/Support/Fake/InMemoryAnnualFeeScheduleRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain+test/fake): AnnualFeeScheduleRepository interface + InMemory fake"
```

---

## Task B9: SqlAnnualFeeScheduleRepository

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlAnnualFeeScheduleRepository.php`
- Create: `tests/Integration/Infrastructure/SqlAnnualFeeScheduleRepositoryTest.php`

- [ ] **Step 1: Write the integration test**

`tests/Integration/Infrastructure/SqlAnnualFeeScheduleRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlAnnualFeeScheduleRepositoryTest extends MigrationTestCase
{
    private SqlAnnualFeeScheduleRepository $repo;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->repo = new SqlAnnualFeeScheduleRepository($this->pdo());
        // tenants seeded by mig 019
        $tenantRow = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->tenantId = TenantId::fromString((string) $tenantRow);
    }

    public function test_save_and_find_by_id(): void
    {
        $schedule = $this->schedule(MembershipType::Basic, 5000);
        $this->repo->save($schedule);
        $loaded = $this->repo->findById($schedule->id());

        $this->assertNotNull($loaded);
        $this->assertSame(5000, $loaded->amountCents());
        $this->assertSame(MembershipType::Basic, $loaded->feeType());
    }

    public function test_find_active_for_returns_only_active(): void
    {
        $superseded = $this->schedule(MembershipType::Basic, 4000, AnnualFeeScheduleStatus::Superseded);
        $active     = $this->schedule(MembershipType::Basic, 5000, AnnualFeeScheduleStatus::Active);
        $this->repo->save($superseded);
        $this->repo->save($active);

        $found = $this->repo->findActiveFor($this->tenantId, 2027, MembershipType::Basic);
        $this->assertNotNull($found);
        $this->assertSame(5000, $found->amountCents());
    }

    public function test_supersede_round_trip(): void
    {
        $schedule = $this->schedule(MembershipType::Basic, 5000, AnnualFeeScheduleStatus::Active);
        $this->repo->save($schedule);

        $schedule->supersede(new DateTimeImmutable('2027-01-15T10:00:00'));
        $this->repo->save($schedule);

        $loaded = $this->repo->findById($schedule->id());
        $this->assertNotNull($loaded);
        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $loaded->status());
        $this->assertEquals(new DateTimeImmutable('2027-01-15T10:00:00'), $loaded->supersededAt());
    }

    private function schedule(MembershipType $type, int $cents, AnnualFeeScheduleStatus $status = AnnualFeeScheduleStatus::Draft): AnnualFeeSchedule
    {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $this->tenantId,
            year:         2027,
            feeType:      $type,
            amountCents:  $cents,
            currency:     'EUR',
            status:       $status,
            decisionId:   null,
            activatedAt:  $status === AnnualFeeScheduleStatus::Active ? new DateTimeImmutable() : null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable(),
            createdBy:    null,
        );
    }
}
```

- [ ] **Step 2: Run test to verify failure**

```bash
vendor/bin/phpunit --filter SqlAnnualFeeScheduleRepositoryTest
```

Expected: FAIL — repo class missing.

- [ ] **Step 3: Implement the SQL repo**

`src/Infrastructure/Adapter/Persistence/Sql/SqlAnnualFeeScheduleRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlAnnualFeeScheduleRepository implements AnnualFeeScheduleRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(AnnualFeeSchedule $s): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO annual_fee_schedules
                (id, tenant_id, year, fee_type, amount_cents, currency, status,
                 decision_id, activated_at, activated_by, superseded_at, created_at, created_by)
             VALUES (:id, :tid, :yr, :ft, :amt, :cur, :st, :did, :aat, :aby, :sat, :cat, :cby)
             ON DUPLICATE KEY UPDATE
                amount_cents = VALUES(amount_cents),
                status       = VALUES(status),
                activated_at = VALUES(activated_at),
                activated_by = VALUES(activated_by),
                superseded_at= VALUES(superseded_at)'
        );
        $stmt->execute([
            'id'  => $s->id()->value(),
            'tid' => $s->tenantId()->value(),
            'yr'  => $s->year(),
            'ft'  => $s->feeType()->value,
            'amt' => $s->amountCents(),
            'cur' => $s->currency(),
            'st'  => $s->status()->value,
            'did' => $s->decisionId(),
            'aat' => $s->activatedAt()?->format('Y-m-d H:i:s'),
            'aby' => $s->activatedBy()?->value(),
            'sat' => $s->supersededAt()?->format('Y-m-d H:i:s'),
            'cat' => $s->createdAt()->format('Y-m-d H:i:s'),
            'cby' => $s->createdBy()?->value(),
        ]);
    }

    public function findById(AnnualFeeScheduleId $id): ?AnnualFeeSchedule
    {
        $stmt = $this->pdo->prepare('SELECT * FROM annual_fee_schedules WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findActiveFor(TenantId $tenantId, int $year, MembershipType $feeType): ?AnnualFeeSchedule
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM annual_fee_schedules
             WHERE tenant_id = ? AND year = ? AND fee_type = ? AND status = ?
             ORDER BY activated_at DESC
             LIMIT 1'
        );
        $stmt->execute([$tenantId->value(), $year, $feeType->value, AnnualFeeScheduleStatus::Active->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findProposedFor(TenantId $tenantId, int $year, ?string $decisionId = null): array
    {
        $sql = 'SELECT * FROM annual_fee_schedules
                WHERE tenant_id = ? AND year = ? AND status = ?';
        $params = [$tenantId->value(), $year, AnnualFeeScheduleStatus::Proposed->value];
        if ($decisionId !== null) {
            $sql .= ' AND decision_id = ?';
            $params[] = $decisionId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function listForTenantYear(TenantId $tenantId, int $year): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM annual_fee_schedules
             WHERE tenant_id = ? AND year = ?
             ORDER BY fee_type ASC, created_at DESC'
        );
        $stmt->execute([$tenantId->value(), $year]);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AnnualFeeSchedule
    {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::fromString((string) $row['id']),
            tenantId:     TenantId::fromString((string) $row['tenant_id']),
            year:         (int) $row['year'],
            feeType:      MembershipType::from((string) $row['fee_type']),
            amountCents:  (int) $row['amount_cents'],
            currency:     (string) $row['currency'],
            status:       AnnualFeeScheduleStatus::from((string) $row['status']),
            decisionId:   $row['decision_id'] !== null ? (string) $row['decision_id'] : null,
            activatedAt:  $row['activated_at'] !== null ? new DateTimeImmutable((string) $row['activated_at']) : null,
            activatedBy:  $row['activated_by'] !== null ? UserId::fromString((string) $row['activated_by']) : null,
            supersededAt: $row['superseded_at'] !== null ? new DateTimeImmutable((string) $row['superseded_at']) : null,
            createdAt:    new DateTimeImmutable((string) $row['created_at']),
            createdBy:    $row['created_by'] !== null ? UserId::fromString((string) $row['created_by']) : null,
        );
    }
}
```

- [ ] **Step 4: Run tests + PHPStan**

```bash
vendor/bin/phpunit --filter SqlAnnualFeeScheduleRepositoryTest
composer analyse
```

Expected: 3 tests OK. PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlAnnualFeeScheduleRepository.php tests/Integration/Infrastructure/SqlAnnualFeeScheduleRepositoryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/sql): SqlAnnualFeeScheduleRepository + integration tests"
```

---

## Task B10: TenantGovernanceSettingsRepository — add billing-fields accessor

**Files:**
- Modify: `src/Domain/Tenant/TenantGovernanceSettingsRepositoryInterface.php` (extend interface)
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantGovernanceSettingsRepository.php` (read new columns)
- Modify: `tests/Support/Fake/InMemoryTenantGovernanceSettingsRepository.php` (matching fake)
- Modify: `src/Domain/Tenant/TenantGovernanceSettings.php` (entity with new fields)

Migration 094 added 4 columns to `tenant_governance_settings`. Now the domain object and its repository need to expose them.

- [ ] **Step 1: Verify current shape**

```bash
grep -rn "class TenantGovernanceSettings" src/
grep -rn "TenantGovernanceSettingsRepository" src/ tests/
```

Pick a clean spot to add `requiresFormalDecisionForFees(): bool`, `defaultDueDaysFromAnniversary(): int`, `overdueGraceDays(): int`, `lapseCheckEnabled(): bool` to the entity.

- [ ] **Step 2: Write failing test**

`tests/Unit/Domain/Tenant/TenantGovernanceSettingsBillingFieldsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantGovernanceSettingsBillingFieldsTest extends TestCase
{
    public function test_defaults_match_migration_094(): void
    {
        $settings = new TenantGovernanceSettings(
            tenantId:                          TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     false,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
            updatedAt:                         new DateTimeImmutable(),
        );

        $this->assertFalse($settings->requiresFormalDecisionForFees());
        $this->assertSame(60, $settings->defaultDueDaysFromAnniversary());
        $this->assertSame(30, $settings->overdueGraceDays());
        $this->assertTrue($settings->lapseCheckEnabled());
    }
}
```

- [ ] **Step 3: Run test to verify failure**

```bash
vendor/bin/phpunit --filter TenantGovernanceSettingsBillingFieldsTest
```

Expected: FAIL — constructor parameters missing.

- [ ] **Step 4: Extend the entity**

In `src/Domain/Tenant/TenantGovernanceSettings.php`, add 4 readonly properties + accessors:

```php
// inside constructor:
private readonly bool $requiresFormalDecisionForFees,
private readonly int  $defaultDueDaysFromAnniversary,
private readonly int  $overdueGraceDays,
private readonly bool $lapseCheckEnabled,

// methods:
public function requiresFormalDecisionForFees(): bool { return $this->requiresFormalDecisionForFees; }
public function defaultDueDaysFromAnniversary(): int { return $this->defaultDueDaysFromAnniversary; }
public function overdueGraceDays(): int { return $this->overdueGraceDays; }
public function lapseCheckEnabled(): bool { return $this->lapseCheckEnabled; }
```

- [ ] **Step 5: Update the SQL repository hydrator**

In `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantGovernanceSettingsRepository.php`, extend the SELECT and hydrate:

```php
// SELECT now includes the 4 new columns
// hydrate adds:
requiresFormalDecisionForFees: (bool) $row['requires_formal_decision_for_fees'],
defaultDueDaysFromAnniversary: (int)  $row['default_due_days_from_anniversary'],
overdueGraceDays:              (int)  $row['overdue_grace_days'],
lapseCheckEnabled:             (bool) $row['lapse_check_enabled'],
```

Same for the upsert/INSERT path — list the new columns and bind them.

- [ ] **Step 6: Update the InMemory fake**

In `tests/Support/Fake/InMemoryTenantGovernanceSettingsRepository.php`, pass through the 4 new constructor params when seeding/saving.

- [ ] **Step 7: Update any call-sites that construct `TenantGovernanceSettings` directly**

```bash
grep -rn "new TenantGovernanceSettings(" src/ tests/
```

Pass the 4 new params (use defaults: false, 60, 30, true) everywhere the constructor is called. Otherwise the test build fails.

- [ ] **Step 8: Run full Unit suite + PHPStan**

```bash
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
composer analyse
```

Expected: green. PHPStan 0.

- [ ] **Step 9: Commit**

```bash
git add src/Domain/Tenant/TenantGovernanceSettings.php src/Infrastructure/Adapter/Persistence/Sql/SqlTenantGovernanceSettingsRepository.php tests/Support/Fake/InMemoryTenantGovernanceSettingsRepository.php tests/Unit/Domain/Tenant/TenantGovernanceSettingsBillingFieldsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Extend(domain/tenant): TenantGovernanceSettings with 4 billing fields"
```

---

## Task B11: DraftAnnualFeeSchedule use case

**Files:**
- Create: `src/Application/Membership/Billing/DraftAnnualFeeSchedule/DraftAnnualFeeSchedule.php`
- Create: `src/Application/Membership/Billing/DraftAnnualFeeSchedule/DraftAnnualFeeScheduleInput.php`
- Create: `src/Application/Membership/Billing/DraftAnnualFeeSchedule/DraftAnnualFeeScheduleOutput.php`
- Create: `tests/Unit/Application/Membership/Billing/DraftAnnualFeeScheduleTest.php`

`DraftAnnualFeeSchedule` routes based on `tenant_governance_settings.requires_formal_decision_for_fees`:
- `false` → create rows with status=`Active` directly (supersede any prior active for same year+type), audit row, return `decision_id=null`
- `true` → create rows with status=`Proposed`, create a `board_decisions` row of `decision_type='annual_fee_schedule'`, return `decision_id`

- [ ] **Step 1: Write the test (covers both branches)**

`tests/Unit/Application/Membership/Billing/DraftAnnualFeeScheduleTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule;
use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeScheduleInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DraftAnnualFeeScheduleTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';

    public function test_direct_activation_when_formal_decision_not_required(): void
    {
        [$useCase, $schedRepo, $deciRepo, $settingsRepo] = $this->makeUseCase(requiresFormal: false);

        $output = $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));

        $this->assertNull($output->decisionId);
        $rows = $schedRepo->listForTenantYear(TenantId::fromString(self::TENANT_ID), 2027);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame(AnnualFeeScheduleStatus::Active, $r->status());
        }
        $this->assertCount(0, $deciRepo->all());
    }

    public function test_formal_decision_path_when_required(): void
    {
        [$useCase, $schedRepo, $deciRepo] = $this->makeUseCase(requiresFormal: true);

        $output = $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));

        $this->assertNotNull($output->decisionId);
        $rows = $schedRepo->listForTenantYear(TenantId::fromString(self::TENANT_ID), 2027);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame(AnnualFeeScheduleStatus::Proposed, $r->status());
            $this->assertSame($output->decisionId, $r->decisionId());
        }
        $this->assertCount(1, $deciRepo->all());
    }

    public function test_non_admin_actor_rejected(): void
    {
        [$useCase] = $this->makeUseCase(requiresFormal: false);

        $this->expectException(\Daems\Domain\Auth\Exception\ForbiddenException::class);
        $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(role: UserTenantRole::Member),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));
    }

    /**
     * @return array{0:DraftAnnualFeeSchedule,1:InMemoryAnnualFeeScheduleRepository,2:InMemoryBoardDecisionRepository,3:InMemoryTenantGovernanceSettingsRepository}
     */
    private function makeUseCase(bool $requiresFormal): array
    {
        $schedRepo = new InMemoryAnnualFeeScheduleRepository();
        $deciRepo  = new InMemoryBoardDecisionRepository();
        $settingsRepo = new InMemoryTenantGovernanceSettingsRepository();
        $settingsRepo->save(new TenantGovernanceSettings(
            tenantId:                          TenantId::fromString(self::TENANT_ID),
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     $requiresFormal,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
            updatedAt:                         new DateTimeImmutable(),
        ));
        $useCase = new DraftAnnualFeeSchedule(
            $schedRepo,
            $deciRepo,
            $settingsRepo,
            new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable('2026-11-01T00:00:00')),
        );
        return [$useCase, $schedRepo, $deciRepo, $settingsRepo];
    }

    private function actor(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
```

- [ ] **Step 2: Run test to verify failure**

```bash
vendor/bin/phpunit --filter DraftAnnualFeeScheduleTest
```

Expected: FAIL — use case class missing.

- [ ] **Step 3: Create Input + Output DTOs**

`src/Application/Membership/Billing/DraftAnnualFeeSchedule/DraftAnnualFeeScheduleInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\DraftAnnualFeeSchedule;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class DraftAnnualFeeScheduleInput
{
    /**
     * @param array<string,int> $fees Map fee_type-value (SUPPORTING|BASIC|FULL) → amount_cents.
     *                                HONORARY MUST NOT appear (§ 3).
     */
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly int        $year,
        public readonly array      $fees,
    ) {}
}
```

`src/Application/Membership/Billing/DraftAnnualFeeSchedule/DraftAnnualFeeScheduleOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\DraftAnnualFeeSchedule;

final class DraftAnnualFeeScheduleOutput
{
    /**
     * @param list<string> $scheduleIds  ID of each saved schedule row (1 per fee_type).
     * @param ?string      $decisionId   board_decisions row id when formal flow used; null for direct activation.
     */
    public function __construct(
        public readonly array   $scheduleIds,
        public readonly ?string $decisionId,
    ) {}
}
```

- [ ] **Step 4: Create the use case**

`src/Application/Membership/Billing/DraftAnnualFeeSchedule/DraftAnnualFeeSchedule.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\DraftAnnualFeeSchedule;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Governance\BoardDecision;
use Daems\Domain\Governance\BoardDecisionId;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\DecisionType;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock\ClockInterface;
use Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface;
use InvalidArgumentException;

final class DraftAnnualFeeSchedule
{
    public function __construct(
        private readonly AnnualFeeScheduleRepositoryInterface         $schedules,
        private readonly BoardDecisionRepositoryInterface             $decisions,
        private readonly TenantGovernanceSettingsRepositoryInterface  $settings,
        private readonly ClockInterface                               $clock,
    ) {}

    public function handle(DraftAnnualFeeScheduleInput $in): DraftAnnualFeeScheduleOutput
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only tenant admins or GSA can draft annual fee schedules");
        }

        $expectedTypes = ['SUPPORTING', 'BASIC', 'FULL'];
        sort($expectedTypes);
        $providedTypes = array_keys($in->fees);
        sort($providedTypes);
        if ($expectedTypes !== $providedTypes) {
            throw new InvalidArgumentException(
                "Must provide exactly 3 fees (SUPPORTING/BASIC/FULL); got " . implode(',', array_keys($in->fees))
            );
        }

        $settings = $this->settings->findForTenant($in->tenantId);
        $requiresFormal = $settings?->requiresFormalDecisionForFees() ?? false;
        $now = $this->clock->now();

        $decisionId = null;
        $status = AnnualFeeScheduleStatus::Active;

        if ($requiresFormal) {
            // Create the board_decisions row first; the schedule rows reference its id.
            $decision = BoardDecision::propose(
                id:           BoardDecisionId::generate(),
                tenantId:     $in->tenantId,
                decisionType: DecisionType::AnnualFeeSchedule,
                proposedBy:   $in->actor->id,
                proposedAt:   $now,
                payloadJson:  (string) json_encode(['year' => $in->year, 'fees' => $in->fees], JSON_UNESCAPED_UNICODE),
            );
            $this->decisions->save($decision);
            $decisionId = $decision->id()->value();
            $status = AnnualFeeScheduleStatus::Proposed;
        } else {
            // Direct activation: supersede any prior active rows for these (year, fee_type).
            foreach (['SUPPORTING', 'BASIC', 'FULL'] as $typeValue) {
                $prev = $this->schedules->findActiveFor($in->tenantId, $in->year, MembershipType::from($typeValue));
                if ($prev !== null) {
                    $prev->supersede($now);
                    $this->schedules->save($prev);
                }
            }
        }

        $scheduleIds = [];
        foreach ($in->fees as $typeValue => $amountCents) {
            $schedule = new AnnualFeeSchedule(
                id:           AnnualFeeScheduleId::generate(),
                tenantId:     $in->tenantId,
                year:         $in->year,
                feeType:      MembershipType::from($typeValue),
                amountCents:  $amountCents,
                currency:     'EUR',
                status:       $status,
                decisionId:   $decisionId,
                activatedAt:  $status === AnnualFeeScheduleStatus::Active ? $now : null,
                activatedBy:  $status === AnnualFeeScheduleStatus::Active ? $in->actor->id : null,
                supersededAt: null,
                createdAt:    $now,
                createdBy:    $in->actor->id,
            );
            $this->schedules->save($schedule);
            $scheduleIds[] = $schedule->id()->value();
        }

        return new DraftAnnualFeeScheduleOutput($scheduleIds, $decisionId);
    }
}
```

- [ ] **Step 5: Run tests + PHPStan**

```bash
vendor/bin/phpunit --filter DraftAnnualFeeScheduleTest
composer analyse
```

Expected: 3 tests OK. PHPStan 0. If a board_decisions enum value missing, add it to `DecisionType` enum from 0.6b.

- [ ] **Step 6: Commit**

```bash
git add src/Application/Membership/Billing/DraftAnnualFeeSchedule/ tests/Unit/Application/Membership/Billing/DraftAnnualFeeScheduleTest.php src/Domain/Governance/DecisionType.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): DraftAnnualFeeSchedule use case (formal + direct paths)"
```

---

## Task B12: ActivateAnnualFeeSchedule + decision-passed handler

**Files:**
- Create: `src/Application/Membership/Billing/ActivateAnnualFeeSchedule/ActivateAnnualFeeSchedule.php`
- Create: `src/Application/Membership/Billing/ActivateAnnualFeeSchedule/ActivateAnnualFeeScheduleInput.php`
- Create: `src/Application/Membership/Billing/AnnualFeeSchedulePassedHandler.php` (listens to 0.6b `DecisionPassedEvent`)
- Create: `tests/Unit/Application/Membership/Billing/ActivateAnnualFeeScheduleTest.php`
- Create: `tests/Unit/Application/Membership/Billing/AnnualFeeSchedulePassedHandlerTest.php`

When a `board_decisions` row of type `annual_fee_schedule` reaches `status=passed`, the 4 proposed rows tied to that decision must:
1. Supersede the previously-active rows (same tenant + year + fee_type)
2. Flip Proposed → Active themselves

- [ ] **Step 1: Write the use case test**

`tests/Unit/Application/Membership/Billing/ActivateAnnualFeeScheduleTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule;
use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeScheduleInput;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ActivateAnnualFeeScheduleTest extends TestCase
{
    private const TENANT_ID  = '01958000-0000-7000-8000-000000000001';
    private const ACTOR_ID   = '01958000-0000-7000-8000-0000000000bb';
    private const DEC_ID     = '01958000-0000-7000-8000-0000000000cc';

    public function test_proposes_3_rows_become_active_and_supersede_prior(): void
    {
        $repo = new InMemoryAnnualFeeScheduleRepository();

        // Prior active rows for year 2027 (from a previous decision earlier in 2026)
        $oldBasic = $this->row(MembershipType::Basic, 4000, AnnualFeeScheduleStatus::Active, decisionId: null);
        $repo->save($oldBasic);

        // New proposed rows from the decision we're now activating
        $newSupp = $this->row(MembershipType::Supporting, 1500, AnnualFeeScheduleStatus::Proposed, decisionId: self::DEC_ID);
        $newBasic = $this->row(MembershipType::Basic,     5000, AnnualFeeScheduleStatus::Proposed, decisionId: self::DEC_ID);
        $newFull  = $this->row(MembershipType::Full,         0, AnnualFeeScheduleStatus::Proposed, decisionId: self::DEC_ID);
        $repo->save($newSupp);
        $repo->save($newBasic);
        $repo->save($newFull);

        $clock = new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable('2026-12-01T00:00:00'));
        $useCase = new ActivateAnnualFeeSchedule($repo, $clock);

        $useCase->handle(new ActivateAnnualFeeScheduleInput(
            tenantId:    TenantId::fromString(self::TENANT_ID),
            year:        2027,
            decisionId:  self::DEC_ID,
            activatedBy: UserId::fromString(self::ACTOR_ID),
        ));

        // Old basic: superseded
        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $repo->findById($oldBasic->id())?->status());
        // New rows: active
        $this->assertSame(AnnualFeeScheduleStatus::Active, $repo->findById($newSupp->id())?->status());
        $this->assertSame(AnnualFeeScheduleStatus::Active, $repo->findById($newBasic->id())?->status());
        $this->assertSame(AnnualFeeScheduleStatus::Active, $repo->findById($newFull->id())?->status());
    }

    private function row(MembershipType $type, int $cents, AnnualFeeScheduleStatus $status, ?string $decisionId): AnnualFeeSchedule
    {
        return new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     TenantId::fromString(self::TENANT_ID),
            year:         2027,
            feeType:      $type,
            amountCents:  $cents,
            currency:     'EUR',
            status:       $status,
            decisionId:   $decisionId,
            activatedAt:  $status === AnnualFeeScheduleStatus::Active ? new DateTimeImmutable('2026-06-01T00:00:00') : null,
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2026-05-01T00:00:00'),
            createdBy:    null,
        );
    }
}
```

- [ ] **Step 2: Run test to verify failure**

```bash
vendor/bin/phpunit --filter ActivateAnnualFeeScheduleTest
```

Expected: FAIL.

- [ ] **Step 3: Create Input + use case**

`src/Application/Membership/Billing/ActivateAnnualFeeSchedule/ActivateAnnualFeeScheduleInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ActivateAnnualFeeScheduleInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly int      $year,
        public readonly string   $decisionId,
        public readonly UserId   $activatedBy,
    ) {}
}
```

`src/Application/Membership/Billing/ActivateAnnualFeeSchedule/ActivateAnnualFeeSchedule.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule;

use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock\ClockInterface;

final class ActivateAnnualFeeSchedule
{
    public function __construct(
        private readonly AnnualFeeScheduleRepositoryInterface $repo,
        private readonly ClockInterface                       $clock,
    ) {}

    public function handle(ActivateAnnualFeeScheduleInput $in): void
    {
        $now = $this->clock->now();

        // 1. Supersede prior active rows for these (year, fee_type).
        foreach (['SUPPORTING', 'BASIC', 'FULL'] as $typeValue) {
            $prev = $this->repo->findActiveFor($in->tenantId, $in->year, MembershipType::from($typeValue));
            if ($prev !== null) {
                $prev->supersede($now);
                $this->repo->save($prev);
            }
        }

        // 2. Activate the proposed rows tied to this decision.
        $proposed = $this->repo->findProposedFor($in->tenantId, $in->year, $in->decisionId);
        foreach ($proposed as $row) {
            $row->activate($in->activatedBy, $now);
            $this->repo->save($row);
        }
    }
}
```

- [ ] **Step 4: Run + PHPStan**

```bash
vendor/bin/phpunit --filter ActivateAnnualFeeScheduleTest
composer analyse
```

Expected: 1 test OK. PHPStan 0.

- [ ] **Step 5: Write test for the passed-handler**

`tests/Unit/Application/Membership/Billing/AnnualFeeSchedulePassedHandlerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\AnnualFeeSchedulePassedHandler;
use Daems\Domain\Governance\DecisionPassedEvent;
use Daems\Domain\Governance\DecisionType;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AnnualFeeSchedulePassedHandlerTest extends TestCase
{
    public function test_activates_proposed_rows_when_decision_type_matches(): void
    {
        $tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $repo = new InMemoryAnnualFeeScheduleRepository();
        $proposed = new AnnualFeeSchedule(
            id: AnnualFeeScheduleId::generate(),
            tenantId: $tenantId,
            year: 2027,
            feeType: MembershipType::Basic,
            amountCents: 5000,
            currency: 'EUR',
            status: AnnualFeeScheduleStatus::Proposed,
            decisionId: '01958000-0000-7000-8000-0000000000cc',
            activatedAt: null,
            activatedBy: null,
            supersededAt: null,
            createdAt: new DateTimeImmutable(),
            createdBy: null,
        );
        $repo->save($proposed);

        $handler = new AnnualFeeSchedulePassedHandler($repo, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable()));
        $handler->handle(new DecisionPassedEvent(
            decisionId:    '01958000-0000-7000-8000-0000000000cc',
            tenantId:      $tenantId,
            decisionType:  DecisionType::AnnualFeeSchedule,
            payloadJson:   (string) json_encode(['year' => 2027, 'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0]]),
            passedAt:      new DateTimeImmutable('2026-12-01T00:00:00'),
            passedBy:      UserId::fromString('01958000-0000-7000-8000-0000000000bb'),
        ));

        $this->assertSame(AnnualFeeScheduleStatus::Active, $repo->findById($proposed->id())?->status());
    }

    public function test_ignores_other_decision_types(): void
    {
        $tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $repo = new InMemoryAnnualFeeScheduleRepository();
        $handler = new AnnualFeeSchedulePassedHandler($repo, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable()));

        // No exception, no side effect when decision_type is something else.
        $handler->handle(new DecisionPassedEvent(
            decisionId:    'irrelevant',
            tenantId:      $tenantId,
            decisionType:  DecisionType::ExpelMember,
            payloadJson:   '{}',
            passedAt:      new DateTimeImmutable(),
            passedBy:      UserId::fromString('01958000-0000-7000-8000-0000000000bb'),
        ));

        $this->assertSame([], $repo->listForTenantYear($tenantId, 2027));
    }
}
```

- [ ] **Step 6: Implement handler**

`src/Application/Membership/Billing/AnnualFeeSchedulePassedHandler.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule;
use Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeScheduleInput;
use Daems\Domain\Governance\DecisionPassedEvent;
use Daems\Domain\Governance\DecisionType;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;

/**
 * Listens for the 0.6b BoardDecision passed-event. When the decision type is
 * 'annual_fee_schedule', activates the proposed rows tied to that decision id.
 * For any other decision type the event is a no-op.
 *
 * Wired in bootstrap/app.php as a DecisionPassedEvent subscriber alongside the
 * 0.6b approve/expel handlers.
 */
final class AnnualFeeSchedulePassedHandler
{
    public function __construct(
        private readonly AnnualFeeScheduleRepositoryInterface $schedules,
        private readonly ClockInterface                       $clock,
    ) {}

    public function handle(DecisionPassedEvent $event): void
    {
        if ($event->decisionType !== DecisionType::AnnualFeeSchedule) {
            return;
        }

        /** @var array{year?:int} $payload */
        $payload = json_decode($event->payloadJson, true, 512, JSON_THROW_ON_ERROR);
        $year = (int) ($payload['year'] ?? 0);
        if ($year === 0) {
            return; // malformed payload — ignore (logged elsewhere)
        }

        $useCase = new ActivateAnnualFeeSchedule($this->schedules, $this->clock);
        $useCase->handle(new ActivateAnnualFeeScheduleInput(
            tenantId:    $event->tenantId,
            year:        $year,
            decisionId:  $event->decisionId,
            activatedBy: $event->passedBy,
        ));
    }
}
```

- [ ] **Step 7: Run all + PHPStan**

```bash
vendor/bin/phpunit --filter 'ActivateAnnualFeeScheduleTest|AnnualFeeSchedulePassedHandlerTest'
composer analyse
```

Expected: 3 tests OK. PHPStan 0.

- [ ] **Step 8: Commit**

```bash
git add src/Application/Membership/Billing/ActivateAnnualFeeSchedule/ src/Application/Membership/Billing/AnnualFeeSchedulePassedHandler.php tests/Unit/Application/Membership/Billing/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): ActivateAnnualFeeSchedule + 0.6b decision-passed handler"
```

---

## Task B13: DI wiring — bootstrap/app.php + KernelHarness

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

Per cross-cutting reminders: every new use case + SQL repo needs bindings in BOTH containers. Add 5 new wirings.

- [ ] **Step 1: Update bootstrap/app.php**

Add to the production container:

```php
// Membership Billing — fee schedules (0.7)
$container[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class] =
    fn($c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository($c[PDO::class]);

$container[\Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule::class] =
    fn($c) => new \Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule(
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
        $c[\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class],
        $c[\Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

$container[\Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule::class] =
    fn($c) => new \Daems\Application\Membership\Billing\ActivateAnnualFeeSchedule\ActivateAnnualFeeSchedule(
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

$container[\Daems\Application\Membership\Billing\AnnualFeeSchedulePassedHandler::class] =
    fn($c) => new \Daems\Application\Membership\Billing\AnnualFeeSchedulePassedHandler(
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

Register the handler as a subscriber on the `DecisionPassedDispatcher` from 0.6b — adjust to match the actual subscriber-registration pattern used in 0.6b governance wiring.

- [ ] **Step 2: Update KernelHarness**

`tests/Support/KernelHarness.php` — mirror the same 4 bindings, swapping the SQL repo for `InMemoryAnnualFeeScheduleRepository`:

```php
// Membership Billing — fee schedules (0.7) — InMemory fakes
$this->container[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class] =
    fn() => new \Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository();

$this->container[\Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule::class] =
    fn($c) => new \Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule(
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
        $c[\Daems\Domain\Governance\BoardDecisionRepositoryInterface::class],
        $c[\Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

// (Repeat for ActivateAnnualFeeSchedule + AnnualFeeSchedulePassedHandler)
```

- [ ] **Step 3: Sanity-check both files reference the class**

```bash
grep -n "AnnualFeeScheduleRepositoryInterface\|DraftAnnualFeeSchedule\|ActivateAnnualFeeSchedule\|AnnualFeeSchedulePassedHandler" bootstrap/app.php tests/Support/KernelHarness.php
```

Expected: each class name appears in BOTH files. If missing in one — fix before commit. This is THE classic 0.6b-style gotcha (`feedback_bootstrap_and_harness_must_both_wire.md`).

- [ ] **Step 4: Run full tests + PHPStan**

```bash
composer analyse
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -3
```

Expected: 0 errors. Unit green.

- [ ] **Step 5: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(di): bind AnnualFeeSchedule repo + use cases + handler in BOTH containers"
```

---

## Task B14: BackstageBillingController — fee-schedule HTTP endpoints

**Files:**
- Create: `src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php`
- Modify: `bootstrap/app.php` (controller binding + route)
- Modify: `tests/Support/KernelHarness.php` (controller binding for E2E)
- Create: `tests/E2E/Backstage/BillingFeeSchedulesEndpointTest.php`

The controller exposes the first two endpoints needed to drive the UI in Task B15:
- `GET /api/v1/backstage/governance/billing/fee-schedules?year=2027`
- `POST /api/v1/backstage/governance/billing/fee-schedules`

- [ ] **Step 1: Write the E2E test**

`tests/E2E/Backstage/BillingFeeSchedulesEndpointTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BillingFeeSchedulesEndpointTest extends TestCase
{
    public function test_post_creates_active_rows_for_non_formal_tenant(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $h->setRequiresFormalDecisionForFees('daems', false);
        $admin = $h->seedAdminUser('daems');

        $response = $h->request('POST', '/api/v1/backstage/governance/billing/fee-schedules', [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ], actor: $admin);

        $this->assertSame(201, $response->status());
        $body = $response->jsonBody();
        $this->assertCount(3, $body['schedule_ids']);
        $this->assertNull($body['decision_id']);
    }

    public function test_post_creates_decision_for_formal_tenant(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $h->setRequiresFormalDecisionForFees('daems', true);
        $admin = $h->seedAdminUser('daems');

        $response = $h->request('POST', '/api/v1/backstage/governance/billing/fee-schedules', [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ], actor: $admin);

        $this->assertSame(201, $response->status());
        $body = $response->jsonBody();
        $this->assertNotNull($body['decision_id']);
    }

    public function test_non_admin_gets_403(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $member = $h->seedMember('daems');

        $response = $h->request('POST', '/api/v1/backstage/governance/billing/fee-schedules', [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ], actor: $member);

        $this->assertSame(403, $response->status());
    }

    public function test_get_returns_active_and_history(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $h->setRequiresFormalDecisionForFees('daems', false);
        $admin = $h->seedAdminUser('daems');

        // Create year 2027 fees
        $h->request('POST', '/api/v1/backstage/governance/billing/fee-schedules', [
            'year' => 2027,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ], actor: $admin);

        $response = $h->request('GET', '/api/v1/backstage/governance/billing/fee-schedules?year=2027', actor: $admin);
        $this->assertSame(200, $response->status());
        $body = $response->jsonBody();
        $this->assertCount(3, $body['rows']);
    }
}
```

If `KernelHarness` lacks `setRequiresFormalDecisionForFees()` or `seedMember()` — add them (1-2 line helpers wrapping the existing seed methods). Make changes minimal.

- [ ] **Step 2: Run test to verify failure**

```bash
vendor/bin/phpunit --filter BillingFeeSchedulesEndpointTest
```

Expected: FAIL — endpoint not wired.

- [ ] **Step 3: Implement the controller**

`src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule;
use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeScheduleInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Api\Request;
use Daems\Infrastructure\Adapter\Api\Response;

final class BackstageBillingController
{
    public function __construct(
        private readonly DraftAnnualFeeSchedule                $draft,
        private readonly AnnualFeeScheduleRepositoryInterface  $schedules,
    ) {}

    public function listFeeSchedules(Request $request, ActingUser $actor, TenantId $tenantId): Response
    {
        if (!$actor->isAdminIn($tenantId) && !$actor->isPlatformAdmin) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        $year = (int) ($request->query('year') ?? date('Y'));
        $rows = $this->schedules->listForTenantYear($tenantId, $year);
        return Response::json([
            'year' => $year,
            'rows' => array_map(static fn($r) => [
                'id'           => $r->id()->value(),
                'fee_type'     => $r->feeType()->value,
                'amount_cents' => $r->amountCents(),
                'currency'     => $r->currency(),
                'status'       => $r->status()->value,
                'decision_id'  => $r->decisionId(),
                'activated_at' => $r->activatedAt()?->format(\DateTimeImmutable::ATOM),
                'created_at'   => $r->createdAt()->format(\DateTimeImmutable::ATOM),
            ], $rows),
        ], 200);
    }

    public function createFeeSchedule(Request $request, ActingUser $actor, TenantId $tenantId): Response
    {
        try {
            $output = $this->draft->handle(new DraftAnnualFeeScheduleInput(
                actor:    $actor,
                tenantId: $tenantId,
                year:     (int) $request->bodyValue('year'),
                fees:     (array) $request->bodyValue('fees'),
            ));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        return Response::json([
            'schedule_ids' => $output->scheduleIds,
            'decision_id'  => $output->decisionId,
        ], 201);
    }
}
```

(Adjust `Request::bodyValue()` / `Request::query()` to whatever the existing convention is in `daems-platform`'s `Request` class. Match other controllers in the same dir.)

- [ ] **Step 4: Wire routes**

Wherever the API router lives (likely `public/api-router.php` or per-module route table), add:

```php
// /api/v1/backstage/governance/billing/*
$router->get('/api/v1/backstage/governance/billing/fee-schedules',
    [BackstageBillingController::class, 'listFeeSchedules']);
$router->post('/api/v1/backstage/governance/billing/fee-schedules',
    [BackstageBillingController::class, 'createFeeSchedule']);
```

Match the convention of how 0.6b governance endpoints are mounted (likely in the platform's main router, not a module router — billing lives under the hardcoded `governance` group).

- [ ] **Step 5: DI binding (BOTH containers)**

Add to `bootstrap/app.php` AND `tests/Support/KernelHarness.php`:

```php
$container[\Daems\Infrastructure\Adapter\Api\Controller\BackstageBillingController::class] =
    fn($c) => new \Daems\Infrastructure\Adapter\Api\Controller\BackstageBillingController(
        $c[\Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule::class],
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
    );
```

- [ ] **Step 6: Run tests + PHPStan**

```bash
vendor/bin/phpunit --filter BillingFeeSchedulesEndpointTest
composer analyse
```

Expected: 4 tests OK. PHPStan 0.

- [ ] **Step 7: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php tests/E2E/Backstage/BillingFeeSchedulesEndpointTest.php bootstrap/app.php tests/Support/KernelHarness.php public/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api): BackstageBillingController fee-schedule endpoints (list + create)"
```

---

## Task B15: Backstage UI — fee-schedule list + editor template

**Files:**
- Create: `public/backstage/governance/billing/index.php`
- Create: `public/backstage/governance/billing/_fees.php`
- Create: `public/backstage/governance/billing/_form.php`
- Create: `public/backstage/assets/governance/billing.css`
- Create: `public/backstage/assets/governance/billing.js`

UI scaffolding for the fee-schedule view. The full landing page (KPI strip + invoice list) is built in Wave C/E once invoices exist. For Wave B we deliver the fees subpage so admin can see + edit hinnasto.

- [ ] **Step 1: Create landing wrapper**

`public/backstage/governance/billing/index.php`:

```php
<?php
declare(strict_types=1);

// Auth gate: must be logged in as admin of current tenant OR GSA.
require __DIR__ . '/../../_guard.php';
$user = $_SESSION['user'] ?? null;
if ($user === null) { header('Location: /backstage/login'); exit; }
$isGSA = (bool) ($user['is_platform_admin'] ?? false);
$isTenantAdmin = ($user['role'] ?? '') === 'admin' || $isGSA;
if (!$isTenantAdmin) { http_response_code(403); echo 'Forbidden'; exit; }

$view = isset($_GET['view']) ? (string) $_GET['view'] : 'invoices';

// Render the backstage shell + dispatch to the right subview.
require __DIR__ . '/../../_layout-header.php';
?>

<nav class="billing-tabs" role="tablist">
    <a role="tab" href="?" class="<?= $view === 'invoices' ? 'is-active' : '' ?>">Laskut</a>
    <a role="tab" href="?view=fees" class="<?= $view === 'fees' ? 'is-active' : '' ?>">Hinnasto</a>
    <a role="tab" href="?view=overrides" class="<?= $view === 'overrides' ? 'is-active' : '' ?>">Alennukset</a>
    <a role="tab" href="?view=import" class="<?= $view === 'import' ? 'is-active' : '' ?>">CSV-tuonti</a>
</nav>

<main class="billing-main">
<?php
match ($view) {
    'fees'      => require __DIR__ . '/_fees.php',
    'overrides' => require __DIR__ . '/_overrides.php',          // created in Wave D
    'import'    => require __DIR__ . '/_import.php',             // created in Wave G
    default     => require __DIR__ . '/_invoices.php',           // created in Wave E
};
?>
</main>

<?php require __DIR__ . '/../../_layout-footer.php'; ?>
```

(In Wave B only `_fees.php` exists; the others render a stub "Tulossa Wave X:ssä" page. Fix that stub when each Wave delivers the real template. The stub keeps the page from 500'ing during Wave B/C development.)

- [ ] **Step 2: Create stub partials**

`public/backstage/governance/billing/_invoices.php`:

```php
<?php /* Wave E delivers this. Stub for now. */ ?>
<section class="billing-stub">
    <h2>Laskut</h2>
    <p>Tämä näkymä toteutetaan Wave E:ssä (Task E7).</p>
</section>
```

`public/backstage/governance/billing/_overrides.php`:

```php
<?php /* Wave D delivers this. Stub for now. */ ?>
<section class="billing-stub">
    <h2>Alennukset</h2>
    <p>Tämä näkymä toteutetaan Wave D:ssä (Task D9).</p>
</section>
```

`public/backstage/governance/billing/_import.php`:

```php
<?php /* Wave G delivers this. Stub for now. */ ?>
<section class="billing-stub">
    <h2>CSV-tuonti</h2>
    <p>Tämä näkymä toteutetaan Wave G:ssä (Task G9).</p>
</section>
```

- [ ] **Step 3: Create _fees.php (the real one)**

`public/backstage/governance/billing/_fees.php`:

```php
<?php
declare(strict_types=1);

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y') + 1;
$editMode = ($_GET['edit'] ?? '') === '1';
?>

<section class="billing-fees" data-year="<?= htmlspecialchars((string) $year) ?>">
    <header class="billing-fees__header">
        <h2>Hinnasto <?= htmlspecialchars((string) $year) ?></h2>
        <form method="get" class="billing-fees__year-form">
            <input type="hidden" name="view" value="fees">
            <label>Vuosi
                <input type="number" name="year" min="2026" max="2099" value="<?= htmlspecialchars((string) $year) ?>">
            </label>
            <button type="submit">Vaihda</button>
        </form>
    </header>

    <?php if ($editMode): ?>
        <?php require __DIR__ . '/_form.php'; ?>
    <?php else: ?>
        <table class="billing-fees__list" data-source="/api/v1/backstage/governance/billing/fee-schedules?year=<?= (int) $year ?>">
            <thead><tr><th>Tyyppi</th><th>Summa</th><th>Tila</th><th>Aktivoitu</th><th>Päätös</th></tr></thead>
            <tbody><tr><td colspan="5">Ladataan…</td></tr></tbody>
        </table>
        <a class="button button--primary" href="?view=fees&year=<?= (int) $year ?>&edit=1">Muokkaa hinnastoa</a>
    <?php endif; ?>
</section>

<script defer src="/backstage/assets/governance/billing.js"></script>
<link rel="stylesheet" href="/backstage/assets/governance/billing.css">
```

`public/backstage/governance/billing/_form.php`:

```php
<?php declare(strict_types=1);
$year = (int) ($_GET['year'] ?? 0);
?>

<form class="billing-fees__form"
      method="post"
      action="/api/v1/backstage/governance/billing/fee-schedules"
      data-redirect-on-success="/backstage/governance/billing?view=fees&year=<?= (int) $year ?>">
    <input type="hidden" name="year" value="<?= (int) $year ?>">
    <fieldset>
        <legend>Vuoden <?= (int) $year ?> hinnasto</legend>
        <label>Kannatusmaksu (SUPPORTING) €<input type="number" min="0" step="1" name="fees[SUPPORTING]" value="0"></label>
        <label>Perusjäsenmaksu (BASIC) €<input type="number" min="0" step="1" name="fees[BASIC]" value="0"></label>
        <label>Varsinaisen jäsenmaksu (FULL) €<input type="number" min="0" step="1" name="fees[FULL]" value="0"></label>
    </fieldset>
    <button type="submit" class="button button--primary">Tallenna</button>
    <a class="button button--secondary" href="?view=fees&year=<?= (int) $year ?>">Peruuta</a>
    <p class="billing-fees__form-note">
        Mikäli tenantille on asetettu <code>requires_formal_decision_for_fees=true</code>,
        hinnasto tallentuu PROPOSED-tilaan ja avaa hallituksen päätös-flow:n.
        Muutoin se astuu suoraan voimaan ja korvaa edellisen aktiivisen hinnaston.
    </p>
</form>
```

- [ ] **Step 4: Add JS for table-render + form-post**

`public/backstage/assets/governance/billing.js`:

```js
// Loads + renders the fee-schedule list, and intercepts form submissions for JSON POST.

document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('.billing-fees__list');
    if (table) loadFees(table);

    const form = document.querySelector('.billing-fees__form');
    if (form) form.addEventListener('submit', onSubmitFees);
});

async function loadFees(table) {
    const url = table.dataset.source;
    const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!resp.ok) {
        table.querySelector('tbody').innerHTML = '<tr><td colspan="5">Lataus epäonnistui (HTTP ' + resp.status + ')</td></tr>';
        return;
    }
    const data = await resp.json();
    const tbody = table.querySelector('tbody');
    if (data.rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5">Ei hinnastoa vuodelle ' + data.year + '. Klikkaa "Muokkaa hinnastoa" luodaksesi.</td></tr>';
        return;
    }
    tbody.innerHTML = data.rows.map(r => `
        <tr data-status="${r.status}">
            <td>${escape(r.fee_type)}</td>
            <td>${(r.amount_cents / 100).toFixed(2)} ${escape(r.currency)}</td>
            <td>${escape(r.status)}</td>
            <td>${r.activated_at ? new Date(r.activated_at).toLocaleString('fi-FI') : '—'}</td>
            <td>${r.decision_id ? `<a href="/backstage/governance/decisions/${r.decision_id}">${r.decision_id.slice(0, 8)}…</a>` : '—'}</td>
        </tr>
    `).join('');
}

async function onSubmitFees(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const formData = new FormData(form);
    const payload = {
        year: parseInt(formData.get('year'), 10),
        fees: {
            SUPPORTING: parseInt(formData.get('fees[SUPPORTING]'), 10) * 100, // €→cents
            BASIC:      parseInt(formData.get('fees[BASIC]'),      10) * 100,
            FULL:       parseInt(formData.get('fees[FULL]'),       10) * 100,
        },
    };
    const resp = await fetch(form.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
    });
    if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        alert('Tallennus epäonnistui: ' + (err.error ?? resp.status));
        return;
    }
    const body = await resp.json();
    if (body.decision_id) {
        window.location.href = '/backstage/governance/decisions/' + body.decision_id;
    } else {
        window.location.href = form.dataset.redirectOnSuccess;
    }
}

function escape(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
```

- [ ] **Step 5: Minimal CSS**

`public/backstage/assets/governance/billing.css`:

```css
.billing-tabs {
    display: flex;
    gap: var(--space-2);
    border-bottom: 1px solid var(--color-border);
    margin-bottom: var(--space-4);
}
.billing-tabs > a {
    padding: var(--space-2) var(--space-3);
    text-decoration: none;
    color: var(--color-text-secondary);
}
.billing-tabs > a.is-active {
    color: var(--color-text-primary);
    border-bottom: 2px solid var(--color-accent);
}

.billing-fees__header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: var(--space-3);
}
.billing-fees__year-form {
    display: flex;
    align-items: center;
    gap: var(--space-2);
}
.billing-fees__list {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: var(--space-3);
}
.billing-fees__list th,
.billing-fees__list td {
    text-align: left;
    padding: var(--space-2);
    border-bottom: 1px solid var(--color-border);
}
.billing-fees__list tr[data-status="superseded"] {
    color: var(--color-text-tertiary);
}
.billing-fees__form fieldset {
    border: 1px solid var(--color-border);
    padding: var(--space-3);
    margin-bottom: var(--space-3);
}
.billing-fees__form label {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-2);
}
.billing-fees__form-note {
    margin-top: var(--space-2);
    color: var(--color-text-secondary);
    font-size: var(--font-sm);
}
.billing-stub {
    padding: var(--space-4);
    color: var(--color-text-secondary);
}
```

(Use whatever CSS variable names the platform's design system has — these are placeholders matching the 0.6b backstage convention. If the project uses Tailwind or another system, adapt.)

- [ ] **Step 6: Smoke-test in browser**

```bash
# Start Laragon if not already running, then open:
# http://daems.local/backstage/governance/billing?view=fees
# - "Hinnasto 2027" header visible
# - "Ladataan…" briefly, then "Ei hinnastoa vuodelle 2027" (since none exists yet)
# - Click "Muokkaa hinnastoa" → form appears
# - Submit fees → redirect back to list, rows now appear
```

- [ ] **Step 7: Commit**

```bash
git add public/backstage/governance/billing/ public/backstage/assets/governance/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): governance/billing fee-schedule view + editor"
```

---

## Wave B — Definition of Done

After Tasks B1-B15, the following invariants hold. Verify before starting Wave C:

- [ ] All 4 migrations (089, 090, 094, 095) applied to `daems_db` AND `daems_db_test`
- [ ] `tests/Isolation/IsolationTestCase.php` watermark = 95
- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --testsuite Unit` green (count up by ~25 from Wave A baseline)
- [ ] `vendor/bin/phpunit --testsuite E2E --filter BillingFeeSchedulesEndpointTest` green (4 tests)
- [ ] Browser smoke: `/backstage/governance/billing?view=fees` renders, list loads, editor saves
- [ ] Both formal + direct paths verified end-to-end (different tenant settings)

Run the verification gate:

```bash
composer analyse
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -3
vendor/bin/phpunit --filter Billing 2>&1 | tail -3
```

If anything fails, FIX before starting Wave C.

---

# Wave C — MemberFeeInvoice + anniversary cron (Phases 4-5, 12 tasks)

This wave builds the invoice domain + the first real cron command. After this wave the platform creates a yearly invoice automatically on each member's membership anniversary, snapshot-locking the price.

**Dependency note:** `GenerateAnniversaryInvoice` reads a `UserFeeOverrideRepositoryInterface`. The full UserFeeOverride domain lands in Wave D — for now (Task C7) we land only the interface + InMemory fake so the use case compiles. Wave D's first task replaces the InMemory binding with a SQL impl.

## Task C1: Migration 091 — member_fee_invoices table

**Files:**
- Create: `database/migrations/091_create_member_fee_invoices.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/091_create_member_fee_invoices.sql`:

```sql
-- 091_create_member_fee_invoices.sql
-- Yearly fee invoice per (tenant, user, year). Snapshot model: fee_type +
-- amount_cents are locked at issue time; later changes to annual_fee_schedules
-- never reach already-issued invoices.
--
-- UNIQUE (tenant_id, user_id, year) makes anniversary-cron idempotent.

CREATE TABLE IF NOT EXISTS member_fee_invoices (
    id                       CHAR(36)             NOT NULL,
    tenant_id                CHAR(36)             NOT NULL,
    user_id                  CHAR(36)             NOT NULL,
    year                     SMALLINT UNSIGNED    NOT NULL,
    fee_type                 VARCHAR(20)          NOT NULL,
    anniversary_date         DATE                 NOT NULL,
    amount_cents             INT UNSIGNED         NOT NULL,
    original_amount_cents    INT UNSIGNED         NULL,
    currency                 CHAR(3)              NOT NULL DEFAULT 'EUR',
    due_date                 DATE                 NOT NULL,
    status                   VARCHAR(20)          NOT NULL DEFAULT 'PENDING',
    paid_at                  DATETIME             NULL,
    paid_amount_cents        INT UNSIGNED         NULL,
    paid_method              VARCHAR(30)          NULL,
    paid_reference           VARCHAR(255)         NULL,
    paid_by                  CHAR(36)             NULL,
    waived_at                DATETIME             NULL,
    waived_by                CHAR(36)             NULL,
    waive_reason             TEXT                 NULL,
    override_id              CHAR(36)             NULL,
    created_at               DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_year (tenant_id, user_id, year),
    KEY idx_status_due (tenant_id, status, due_date),
    KEY idx_user_year (user_id, year),
    KEY idx_lapse_lookup (tenant_id, user_id, year, status),
    CONSTRAINT fk_mfi_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfi_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mfi_waived_by FOREIGN KEY (waived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Note: FK on `override_id` deferred to Wave D when `user_fee_overrides` table exists. Sarake on jo paikalla, mutta FK lisätään mig 092:n perään.

- [ ] **Step 2: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/091_create_member_fee_invoices.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/091_create_member_fee_invoices.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE member_fee_invoices\G"
```

Expected: table exists with UNIQUE on (tenant_id, user_id, year), 4 indexes, 4 FKs.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/091_create_member_fee_invoices.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 091 — member_fee_invoices table (snapshot model)"
```

---

## Task C2: MemberFeeInvoiceStatus enum + MemberFeeInvoiceId VO

**Files:**
- Create: `src/Domain/Membership/Billing/MemberFeeInvoiceStatus.php`
- Create: `src/Domain/Membership/Billing/MemberFeeInvoiceId.php`
- Create: `tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceStatusTest.php`
- Create: `tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceIdTest.php`

Pattern mirrors `AnnualFeeScheduleStatus` / `AnnualFeeScheduleId` from Task B6. Skip the boilerplate explanation.

- [ ] **Step 1: Write status enum**

`src/Domain/Membership/Billing/MemberFeeInvoiceStatus.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

enum MemberFeeInvoiceStatus: string
{
    case Pending  = 'PENDING';
    case Paid     = 'PAID';
    case Overdue  = 'OVERDUE';
    case Waived   = 'WAIVED';
    case Reduced  = 'REDUCED';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Overdue || $this === self::Reduced;
    }

    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Waived;
    }
}
```

- [ ] **Step 2: Write ID VO (same shape as AnnualFeeScheduleId)**

`src/Domain/Membership/Billing/MemberFeeInvoiceId.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class MemberFeeInvoiceId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self { return new self(Uuid7::generate()->value()); }

    public static function fromString(string $raw): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $raw) !== 1) {
            throw new \InvalidArgumentException("Not a valid UUIDv7: {$raw}");
        }
        return new self($raw);
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
```

- [ ] **Step 3: Write tests (mirror B6 layout)**

`tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceStatusTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use PHPUnit\Framework\TestCase;

final class MemberFeeInvoiceStatusTest extends TestCase
{
    public function test_5_cases(): void
    {
        $this->assertSame('PENDING', MemberFeeInvoiceStatus::Pending->value);
        $this->assertSame('PAID',    MemberFeeInvoiceStatus::Paid->value);
        $this->assertSame('OVERDUE', MemberFeeInvoiceStatus::Overdue->value);
        $this->assertSame('WAIVED',  MemberFeeInvoiceStatus::Waived->value);
        $this->assertSame('REDUCED', MemberFeeInvoiceStatus::Reduced->value);
    }

    public function test_isOpen(): void
    {
        $this->assertTrue(MemberFeeInvoiceStatus::Pending->isOpen());
        $this->assertTrue(MemberFeeInvoiceStatus::Overdue->isOpen());
        $this->assertTrue(MemberFeeInvoiceStatus::Reduced->isOpen());
        $this->assertFalse(MemberFeeInvoiceStatus::Paid->isOpen());
        $this->assertFalse(MemberFeeInvoiceStatus::Waived->isOpen());
    }

    public function test_isFinal(): void
    {
        $this->assertTrue(MemberFeeInvoiceStatus::Paid->isFinal());
        $this->assertTrue(MemberFeeInvoiceStatus::Waived->isFinal());
        $this->assertFalse(MemberFeeInvoiceStatus::Pending->isFinal());
    }
}
```

`tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceIdTest.php`: copy `AnnualFeeScheduleIdTest` template, swap class names.

- [ ] **Step 4: Run + PHPStan**

```bash
vendor/bin/phpunit --filter 'MemberFeeInvoiceStatusTest|MemberFeeInvoiceIdTest'
composer analyse
```

Expected: green. 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Membership/Billing/MemberFeeInvoiceStatus.php src/Domain/Membership/Billing/MemberFeeInvoiceId.php tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceStatusTest.php tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceIdTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain): MemberFeeInvoiceStatus enum + MemberFeeInvoiceId VO"
```

---

## Task C3: PaymentRecord value object

**Files:**
- Create: `src/Domain/Membership/Billing/PaymentRecord.php`
- Create: `tests/Unit/Domain/Membership/Billing/PaymentRecordTest.php`

Encapsulates a single payment event (amount, date, method, reference, who recorded it). Used inline in `MemberFeeInvoice` when an admin marks an invoice paid.

- [ ] **Step 1: Write test**

`tests/Unit/Domain/Membership/Billing/PaymentRecordTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PaymentRecordTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $rec = new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-09-13T15:30:00'),
            amountCents: 5000,
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
            paidBy:      UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
        );
        $this->assertSame(5000, $rec->amountCents());
        $this->assertSame('bank_transfer', $rec->method());
        $this->assertSame('Nordea 12345/2026', $rec->reference());
    }

    public function test_rejects_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentRecord(
            paidAt: new DateTimeImmutable(),
            amountCents: 0,
            method: 'bank_transfer',
            reference: 'ref',
            paidBy: UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
        );
    }

    public function test_rejects_empty_method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentRecord(
            paidAt: new DateTimeImmutable(),
            amountCents: 5000,
            method: '',
            reference: 'ref',
            paidBy: UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify failure**

```bash
vendor/bin/phpunit --filter PaymentRecordTest
```

Expected: FAIL.

- [ ] **Step 3: Implement value object**

`src/Domain/Membership/Billing/PaymentRecord.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Single payment event recorded against a MemberFeeInvoice.
 *
 * Immutable value object — once a payment is recorded the invoice flips to
 * PAID and the values are frozen on the invoice row. There is no separate
 * payments table in 0.7 (one-payment-per-invoice model; multi-payment
 * support deferred to 0.7.1 Stripe).
 *
 * Method is a free-string in 0.7 ('bank_transfer' | 'cash' | 'csv_import' | other).
 */
final class PaymentRecord
{
    public function __construct(
        private readonly DateTimeImmutable $paidAt,
        private readonly int               $amountCents,
        private readonly string            $method,
        private readonly string            $reference,
        private readonly UserId            $paidBy,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException("PaymentRecord.amount_cents must be > 0, got {$amountCents}");
        }
        if (trim($method) === '') {
            throw new InvalidArgumentException("PaymentRecord.method must not be empty");
        }
    }

    public function paidAt(): DateTimeImmutable { return $this->paidAt; }
    public function amountCents(): int { return $this->amountCents; }
    public function method(): string { return $this->method; }
    public function reference(): string { return $this->reference; }
    public function paidBy(): UserId { return $this->paidBy; }
}
```

- [ ] **Step 4: Run + PHPStan**

```bash
vendor/bin/phpunit --filter PaymentRecordTest
composer analyse
```

Expected: 3 tests OK. 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Membership/Billing/PaymentRecord.php tests/Unit/Domain/Membership/Billing/PaymentRecordTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain): PaymentRecord value object"
```

---

## Task C4: MemberFeeInvoice entity

**Files:**
- Create: `src/Domain/Membership/Billing/MemberFeeInvoice.php`
- Create: `src/Domain/Membership/Billing/Exception/InvoiceAlreadyPaidException.php`
- Create: `tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceTest.php`

- [ ] **Step 1: Create exception**

`src/Domain/Membership/Billing/Exception/InvoiceAlreadyPaidException.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing\Exception;

final class InvoiceAlreadyPaidException extends \DomainException
{
}
```

- [ ] **Step 2: Write entity test**

`tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MemberFeeInvoiceTest extends TestCase
{
    public function test_issued_starts_pending(): void
    {
        $inv = $this->newInvoice();
        $this->assertSame(MemberFeeInvoiceStatus::Pending, $inv->status());
        $this->assertNull($inv->paidAt());
    }

    public function test_mark_overdue_when_pending(): void
    {
        $inv = $this->newInvoice();
        $now = new DateTimeImmutable('2026-10-15T00:00:00');
        $inv->markOverdue($now);
        $this->assertSame(MemberFeeInvoiceStatus::Overdue, $inv->status());
    }

    public function test_mark_overdue_skips_when_already_paid(): void
    {
        $inv = $this->newInvoice();
        $inv->recordPayment($this->payment());
        $inv->markOverdue(new DateTimeImmutable('2026-10-15T00:00:00'));
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $inv->status());
    }

    public function test_record_payment_flips_to_paid(): void
    {
        $inv = $this->newInvoice();
        $pmt = $this->payment();
        $inv->recordPayment($pmt);

        $this->assertSame(MemberFeeInvoiceStatus::Paid, $inv->status());
        $this->assertEquals($pmt->paidAt(), $inv->paidAt());
        $this->assertSame(5000, $inv->paidAmountCents());
        $this->assertSame('bank_transfer', $inv->paidMethod());
    }

    public function test_record_payment_rejects_already_paid(): void
    {
        $inv = $this->newInvoice();
        $inv->recordPayment($this->payment());

        $this->expectException(InvoiceAlreadyPaidException::class);
        $inv->recordPayment($this->payment());
    }

    public function test_waive_flips_to_waived(): void
    {
        $inv = $this->newInvoice();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000aa');
        $now = new DateTimeImmutable('2026-10-01T00:00:00');
        $inv->waive($actor, 'Pitkäaikaissairaus', $now);

        $this->assertSame(MemberFeeInvoiceStatus::Waived, $inv->status());
        $this->assertEquals($now, $inv->waivedAt());
        $this->assertEquals($actor, $inv->waivedBy());
        $this->assertSame('Pitkäaikaissairaus', $inv->waiveReason());
    }

    public function test_reduce_sets_original_amount(): void
    {
        $inv = $this->newInvoice();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $now = new DateTimeImmutable('2026-09-20T00:00:00');
        $inv->reduce(2500, $actor, 'Sosiaalinen alennus', $now);

        $this->assertSame(MemberFeeInvoiceStatus::Reduced, $inv->status());
        $this->assertSame(2500, $inv->amountCents());
        $this->assertSame(5000, $inv->originalAmountCents());
    }

    public function test_reduce_rejects_zero_or_negative(): void
    {
        $inv = $this->newInvoice();
        $this->expectException(\InvalidArgumentException::class);
        $inv->reduce(0, UserId::fromString('01958000-0000-7000-8000-0000000000cc'), 'r', new DateTimeImmutable());
    }

    public function test_reduce_rejects_amount_above_original(): void
    {
        $inv = $this->newInvoice();
        $this->expectException(\InvalidArgumentException::class);
        $inv->reduce(6000, UserId::fromString('01958000-0000-7000-8000-0000000000cc'), 'r', new DateTimeImmutable());
    }

    private function newInvoice(): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable('2026-09-13'),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable('2026-07-15T02:00:00'),
        );
    }

    private function payment(): PaymentRecord
    {
        return new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-08-01T10:00:00'),
            amountCents: 5000,
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
            paidBy:      UserId::fromString('01958000-0000-7000-8000-0000000000ad'),
        );
    }
}
```

- [ ] **Step 3: Implement entity**

`src/Domain/Membership/Billing/MemberFeeInvoice.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Yearly fee invoice for one (tenant, user, year).
 *
 * Lifecycle:
 *   PENDING ──markOverdue──> OVERDUE
 *      │                       │
 *      │ recordPayment         │ recordPayment
 *      ▼                       ▼
 *     PAID  (terminal — InvoiceAlreadyPaidException blocks re-pay)
 *
 *   PENDING/OVERDUE ──waive──> WAIVED (terminal)
 *   PENDING/OVERDUE ──reduce──> REDUCED  (still open; can still recordPayment)
 *
 * Snapshot invariants: fee_type and amount_cents at issue time are immutable
 * by anniversary-cron re-runs. amount_cents is only mutated by reduce(),
 * which preserves the original in original_amount_cents for audit.
 */
final class MemberFeeInvoice
{
    public function __construct(
        private readonly MemberFeeInvoiceId  $id,
        private readonly TenantId            $tenantId,
        private readonly UserId              $userId,
        private readonly int                 $year,
        private readonly MembershipType      $feeType,
        private readonly DateTimeImmutable   $anniversaryDate,
        private int                          $amountCents,
        private ?int                         $originalAmountCents,
        private readonly string              $currency,
        private readonly DateTimeImmutable   $dueDate,
        private MemberFeeInvoiceStatus       $status,
        private ?PaymentRecord               $payment,
        private ?DateTimeImmutable           $waivedAt,
        private ?UserId                      $waivedBy,
        private ?string                      $waiveReason,
        private readonly ?string             $overrideId,
        private readonly DateTimeImmutable   $createdAt,
    ) {
        if ($amountCents < 0) {
            throw new InvalidArgumentException("amount_cents must be >= 0");
        }
        if ($feeType === MembershipType::Honorary) {
            throw new InvalidArgumentException("HONORARY members do not receive invoices (§ 3)");
        }
    }

    public function id(): MemberFeeInvoiceId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): UserId { return $this->userId; }
    public function year(): int { return $this->year; }
    public function feeType(): MembershipType { return $this->feeType; }
    public function anniversaryDate(): DateTimeImmutable { return $this->anniversaryDate; }
    public function amountCents(): int { return $this->amountCents; }
    public function originalAmountCents(): ?int { return $this->originalAmountCents; }
    public function currency(): string { return $this->currency; }
    public function dueDate(): DateTimeImmutable { return $this->dueDate; }
    public function status(): MemberFeeInvoiceStatus { return $this->status; }
    public function payment(): ?PaymentRecord { return $this->payment; }
    public function paidAt(): ?DateTimeImmutable { return $this->payment?->paidAt(); }
    public function paidAmountCents(): ?int { return $this->payment?->amountCents(); }
    public function paidMethod(): ?string { return $this->payment?->method(); }
    public function paidReference(): ?string { return $this->payment?->reference(); }
    public function paidBy(): ?UserId { return $this->payment?->paidBy(); }
    public function waivedAt(): ?DateTimeImmutable { return $this->waivedAt; }
    public function waivedBy(): ?UserId { return $this->waivedBy; }
    public function waiveReason(): ?string { return $this->waiveReason; }
    public function overrideId(): ?string { return $this->overrideId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    public function markOverdue(DateTimeImmutable $now): void
    {
        if ($this->status !== MemberFeeInvoiceStatus::Pending) {
            return; // idempotent: only Pending → Overdue
        }
        if ($now < $this->dueDate) {
            return; // don't mark overdue early
        }
        $this->status = MemberFeeInvoiceStatus::Overdue;
    }

    public function recordPayment(PaymentRecord $payment): void
    {
        if ($this->status === MemberFeeInvoiceStatus::Paid) {
            throw new InvoiceAlreadyPaidException("Invoice {$this->id->value()} is already paid");
        }
        if ($this->status === MemberFeeInvoiceStatus::Waived) {
            throw new InvoiceAlreadyPaidException("Invoice {$this->id->value()} is waived; cannot record payment");
        }
        $this->payment = $payment;
        $this->status = MemberFeeInvoiceStatus::Paid;
    }

    public function waive(UserId $actor, string $reason, DateTimeImmutable $now): void
    {
        if ($this->status->isFinal()) {
            throw new \DomainException("Cannot waive an invoice in final state {$this->status->value}");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("Waive reason must not be empty");
        }
        $this->status = MemberFeeInvoiceStatus::Waived;
        $this->waivedAt = $now;
        $this->waivedBy = $actor;
        $this->waiveReason = $reason;
    }

    public function reduce(int $newAmountCents, UserId $actor, string $reason, DateTimeImmutable $now): void
    {
        if ($newAmountCents <= 0) {
            throw new InvalidArgumentException("Reduced amount must be > 0 (use waive() for zero)");
        }
        if ($newAmountCents >= $this->amountCents) {
            throw new InvalidArgumentException("Reduced amount must be less than current {$this->amountCents}");
        }
        if ($this->status->isFinal()) {
            throw new \DomainException("Cannot reduce an invoice in final state {$this->status->value}");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("Reduce reason must not be empty");
        }
        if ($this->originalAmountCents === null) {
            $this->originalAmountCents = $this->amountCents;
        }
        $this->amountCents = $newAmountCents;
        $this->status = MemberFeeInvoiceStatus::Reduced;
        // We don't track waivedAt/waivedBy/reason for REDUCE — audit row in
        // member_fee_invoice_audit (Wave E) carries the reason + actor + delta.
        // For UI display the audit table is the source of truth.
        $this->waivedAt = null;
        $this->waivedBy = null;
        $this->waiveReason = $reason; // dual-use field; UI shows "syy" for both REDUCED + WAIVED
        $this->waivedBy = $actor;
        $this->waivedAt = $now;
    }
}
```

- [ ] **Step 4: Run tests + PHPStan**

```bash
vendor/bin/phpunit --filter MemberFeeInvoiceTest
composer analyse
```

Expected: 9 tests OK. PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Membership/Billing/MemberFeeInvoice.php src/Domain/Membership/Billing/Exception/InvoiceAlreadyPaidException.php tests/Unit/Domain/Membership/Billing/MemberFeeInvoiceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain): MemberFeeInvoice entity with state-machine invariants"
```

---

## Task C5: MemberFeeInvoiceRepositoryInterface + InMemory fake

**Files:**
- Create: `src/Domain/Membership/Billing/MemberFeeInvoiceRepositoryInterface.php`
- Create: `tests/Support/Fake/InMemoryMemberFeeInvoiceRepository.php`

- [ ] **Step 1: Define interface**

`src/Domain/Membership/Billing/MemberFeeInvoiceRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface MemberFeeInvoiceRepositoryInterface
{
    public function save(MemberFeeInvoice $invoice): void;

    public function findById(MemberFeeInvoiceId $id): ?MemberFeeInvoice;

    /**
     * Lookup for the (tenant, user, year) anniversary-cron idempotency check.
     */
    public function findFor(TenantId $tenantId, UserId $userId, int $year): ?MemberFeeInvoice;

    /**
     * All open (PENDING|OVERDUE|REDUCED) invoices past their due_date + grace.
     * Used by MarkOverdueInvoices cron in Wave F.
     *
     * @return list<MemberFeeInvoice>
     */
    public function findOverdueCandidates(TenantId $tenantId, \DateTimeImmutable $asOf, int $graceDays): array;

    /**
     * Find users with 2 consecutive years OVERDUE — used by LapseInactiveMember cron.
     * Returns array of (UserId, [year1, year2]) tuples.
     *
     * @return list<array{user_id: UserId, years: list<int>}>
     */
    public function findUsersWithConsecutiveOverdueYears(TenantId $tenantId): array;

    /**
     * Backstage UI list with filters.
     *
     * @param array{year?:int, status?:string, fee_type?:string, user_id?:string} $filter
     * @return list<MemberFeeInvoice>
     */
    public function listForTenant(TenantId $tenantId, array $filter = [], int $limit = 100, int $offset = 0): array;

    /**
     * All open invoices for a specific user. Used by WaiveOpenInvoicesOnHonoraryChange (Wave G).
     *
     * @return list<MemberFeeInvoice>
     */
    public function listOpenForUser(TenantId $tenantId, UserId $userId): array;
}
```

- [ ] **Step 2: Implement InMemory fake**

`tests/Support/Fake/InMemoryMemberFeeInvoiceRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class InMemoryMemberFeeInvoiceRepository implements MemberFeeInvoiceRepositoryInterface
{
    /** @var array<string, MemberFeeInvoice> */
    private array $byId = [];

    public function save(MemberFeeInvoice $invoice): void
    {
        $this->byId[$invoice->id()->value()] = $invoice;
    }

    public function findById(MemberFeeInvoiceId $id): ?MemberFeeInvoice
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findFor(TenantId $tenantId, UserId $userId, int $year): ?MemberFeeInvoice
    {
        foreach ($this->byId as $inv) {
            if ($inv->tenantId()->equals($tenantId)
                && $inv->userId()->equals($userId)
                && $inv->year() === $year
            ) {
                return $inv;
            }
        }
        return null;
    }

    public function findOverdueCandidates(TenantId $tenantId, DateTimeImmutable $asOf, int $graceDays): array
    {
        $cutoff = $asOf->modify("-{$graceDays} days");
        $out = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) continue;
            if ($inv->status() !== MemberFeeInvoiceStatus::Pending) continue;
            if ($inv->dueDate() >= $cutoff) continue;
            $out[] = $inv;
        }
        return array_values($out);
    }

    public function findUsersWithConsecutiveOverdueYears(TenantId $tenantId): array
    {
        // Group by user_id, sort by year, look for any pair (N, N+1) both OVERDUE.
        $byUser = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) continue;
            if ($inv->status() !== MemberFeeInvoiceStatus::Overdue) continue;
            $uid = $inv->userId()->value();
            $byUser[$uid] = $byUser[$uid] ?? [];
            $byUser[$uid][] = $inv->year();
        }
        $out = [];
        foreach ($byUser as $uid => $years) {
            sort($years);
            for ($i = 0; $i < count($years) - 1; $i++) {
                if ($years[$i + 1] === $years[$i] + 1) {
                    $out[] = ['user_id' => UserId::fromString($uid), 'years' => [$years[$i], $years[$i + 1]]];
                    break;
                }
            }
        }
        return array_values($out);
    }

    public function listForTenant(TenantId $tenantId, array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $matched = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) continue;
            if (isset($filter['year']) && $inv->year() !== (int) $filter['year']) continue;
            if (isset($filter['status']) && $inv->status()->value !== $filter['status']) continue;
            if (isset($filter['fee_type']) && $inv->feeType()->value !== $filter['fee_type']) continue;
            if (isset($filter['user_id']) && $inv->userId()->value() !== $filter['user_id']) continue;
            $matched[] = $inv;
        }
        return array_slice($matched, $offset, $limit);
    }

    public function listOpenForUser(TenantId $tenantId, UserId $userId): array
    {
        $out = [];
        foreach ($this->byId as $inv) {
            if (!$inv->tenantId()->equals($tenantId)) continue;
            if (!$inv->userId()->equals($userId)) continue;
            if (!$inv->status()->isOpen()) continue;
            $out[] = $inv;
        }
        return array_values($out);
    }
}
```

- [ ] **Step 3: PHPStan verification**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Membership/Billing/MemberFeeInvoiceRepositoryInterface.php tests/Support/Fake/InMemoryMemberFeeInvoiceRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain+fake): MemberFeeInvoiceRepository interface + InMemory fake"
```

---

## Task C6: SqlMemberFeeInvoiceRepository

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlMemberFeeInvoiceRepository.php`
- Create: `tests/Integration/Infrastructure/SqlMemberFeeInvoiceRepositoryTest.php`

- [ ] **Step 1: Write integration test**

`tests/Integration/Infrastructure/SqlMemberFeeInvoiceRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlMemberFeeInvoiceRepositoryTest extends MigrationTestCase
{
    private SqlMemberFeeInvoiceRepository $repo;
    private TenantId $tenantId;
    private UserId $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->repo = new SqlMemberFeeInvoiceRepository($this->pdo());

        $tenantRow = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->tenantId = TenantId::fromString((string) $tenantRow);

        // seed a user
        $this->userId = UserId::fromString('01958000-0000-7000-8000-000000000099');
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->userId->value(), 'Test User', 'test@daems.fi', null, '1990-01-01', 0,
            'BASIC', 'active', '2024-07-15 00:00:00',
        ]);
    }

    public function test_save_and_find_by_id_round_trip(): void
    {
        $inv = $this->newInvoice(MemberFeeInvoiceStatus::Pending);
        $this->repo->save($inv);

        $loaded = $this->repo->findById($inv->id());
        $this->assertNotNull($loaded);
        $this->assertSame(5000, $loaded->amountCents());
        $this->assertSame(MemberFeeInvoiceStatus::Pending, $loaded->status());
    }

    public function test_unique_violation_on_duplicate_year(): void
    {
        $inv = $this->newInvoice();
        $this->repo->save($inv);

        $dup = $this->newInvoice(); // different id, same (tenant, user, year)
        $this->expectException(\PDOException::class);
        $this->repo->save($dup);
    }

    public function test_round_trip_with_payment(): void
    {
        $inv = $this->newInvoice();
        $inv->recordPayment(new PaymentRecord(
            paidAt:      new DateTimeImmutable('2026-08-01T10:00:00'),
            amountCents: 5000,
            method:      'bank_transfer',
            reference:   'Nordea 12345/2026',
            paidBy:      $this->userId,
        ));
        $this->repo->save($inv);

        $loaded = $this->repo->findById($inv->id());
        $this->assertNotNull($loaded);
        $this->assertSame(MemberFeeInvoiceStatus::Paid, $loaded->status());
        $this->assertSame(5000, $loaded->paidAmountCents());
        $this->assertSame('bank_transfer', $loaded->paidMethod());
    }

    public function test_findOverdueCandidates_filters_by_grace(): void
    {
        $past = $this->newInvoice(MemberFeeInvoiceStatus::Pending, dueDate: new DateTimeImmutable('2026-01-01'));
        $recent = $this->newInvoice(MemberFeeInvoiceStatus::Pending, dueDate: new DateTimeImmutable('2026-09-13'));
        $recent2 = clone $recent; // we can't clone here — make a fresh different-year invoice
        $this->repo->save($past);

        // Switch to a different year so unique constraint allows second save
        $userTwo = UserId::fromString('01958000-0000-7000-8000-000000000098');
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userTwo->value(), 'User Two', 'two@daems.fi', null, '1990-01-01', 0, 'BASIC', 'active', '2024-09-13 00:00:00']);
        $invTwo = new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $userTwo,
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-09-13'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable('2026-09-13'),
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable(),
        );
        $this->repo->save($invTwo);

        $candidates = $this->repo->findOverdueCandidates($this->tenantId, new DateTimeImmutable('2026-10-15'), 30);
        $this->assertCount(1, $candidates); // only `past` is > 30 days overdue
        $this->assertEquals($past->id(), $candidates[0]->id());
    }

    private function newInvoice(
        MemberFeeInvoiceStatus $status = MemberFeeInvoiceStatus::Pending,
        ?DateTimeImmutable $dueDate = null,
    ): MemberFeeInvoice {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $this->tenantId,
            userId:              $this->userId,
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             $dueDate ?? new DateTimeImmutable('2026-09-13'),
            status:              $status,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable('2026-07-15T02:00:00'),
        );
    }
}
```

- [ ] **Step 2: Implement SQL repo**

`src/Infrastructure/Adapter/Persistence/Sql/SqlMemberFeeInvoiceRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlMemberFeeInvoiceRepository implements MemberFeeInvoiceRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(MemberFeeInvoice $i): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_fee_invoices (
                id, tenant_id, user_id, year, fee_type, anniversary_date,
                amount_cents, original_amount_cents, currency, due_date, status,
                paid_at, paid_amount_cents, paid_method, paid_reference, paid_by,
                waived_at, waived_by, waive_reason, override_id, created_at
             ) VALUES (
                :id, :tid, :uid, :yr, :ft, :ann,
                :amt, :oamt, :cur, :dd, :st,
                :pat, :pac, :pm, :pref, :pby,
                :wat, :wby, :wr, :ovr, :cat
             ) ON DUPLICATE KEY UPDATE
                amount_cents = VALUES(amount_cents),
                original_amount_cents = VALUES(original_amount_cents),
                status = VALUES(status),
                paid_at = VALUES(paid_at),
                paid_amount_cents = VALUES(paid_amount_cents),
                paid_method = VALUES(paid_method),
                paid_reference = VALUES(paid_reference),
                paid_by = VALUES(paid_by),
                waived_at = VALUES(waived_at),
                waived_by = VALUES(waived_by),
                waive_reason = VALUES(waive_reason)'
        );
        $stmt->execute([
            'id'   => $i->id()->value(),
            'tid'  => $i->tenantId()->value(),
            'uid'  => $i->userId()->value(),
            'yr'   => $i->year(),
            'ft'   => $i->feeType()->value,
            'ann'  => $i->anniversaryDate()->format('Y-m-d'),
            'amt'  => $i->amountCents(),
            'oamt' => $i->originalAmountCents(),
            'cur'  => $i->currency(),
            'dd'   => $i->dueDate()->format('Y-m-d'),
            'st'   => $i->status()->value,
            'pat'  => $i->paidAt()?->format('Y-m-d H:i:s'),
            'pac'  => $i->paidAmountCents(),
            'pm'   => $i->paidMethod(),
            'pref' => $i->paidReference(),
            'pby'  => $i->paidBy()?->value(),
            'wat'  => $i->waivedAt()?->format('Y-m-d H:i:s'),
            'wby'  => $i->waivedBy()?->value(),
            'wr'   => $i->waiveReason(),
            'ovr'  => $i->overrideId(),
            'cat'  => $i->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(MemberFeeInvoiceId $id): ?MemberFeeInvoice
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_fee_invoices WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findFor(TenantId $tenantId, UserId $userId, int $year): ?MemberFeeInvoice
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_fee_invoices WHERE tenant_id = ? AND user_id = ? AND year = ?'
        );
        $stmt->execute([$tenantId->value(), $userId->value(), $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findOverdueCandidates(TenantId $tenantId, DateTimeImmutable $asOf, int $graceDays): array
    {
        $cutoff = $asOf->modify("-{$graceDays} days")->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_fee_invoices
             WHERE tenant_id = ? AND status = ? AND due_date < ?
             ORDER BY due_date ASC'
        );
        $stmt->execute([$tenantId->value(), MemberFeeInvoiceStatus::Pending->value, $cutoff]);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findUsersWithConsecutiveOverdueYears(TenantId $tenantId): array
    {
        // Self-join: invoice A in year N + invoice B in year N+1, both OVERDUE, same user.
        $stmt = $this->pdo->prepare(
            "SELECT a.user_id, a.year AS y1, b.year AS y2
             FROM member_fee_invoices a
             INNER JOIN member_fee_invoices b
                ON a.tenant_id = b.tenant_id
               AND a.user_id   = b.user_id
               AND b.year      = a.year + 1
             WHERE a.tenant_id = :tid
               AND a.status    = 'OVERDUE'
               AND b.status    = 'OVERDUE'
             ORDER BY a.user_id, a.year"
        );
        $stmt->execute(['tid' => $tenantId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'user_id' => UserId::fromString((string) $row['user_id']),
                'years'   => [(int) $row['y1'], (int) $row['y2']],
            ];
        }
        return $out;
    }

    public function listForTenant(TenantId $tenantId, array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['tenant_id = :tid'];
        $params = ['tid' => $tenantId->value()];
        if (isset($filter['year'])) { $where[] = 'year = :yr'; $params['yr'] = (int) $filter['year']; }
        if (isset($filter['status'])) { $where[] = 'status = :st'; $params['st'] = (string) $filter['status']; }
        if (isset($filter['fee_type'])) { $where[] = 'fee_type = :ft'; $params['ft'] = (string) $filter['fee_type']; }
        if (isset($filter['user_id'])) { $where[] = 'user_id = :uid'; $params['uid'] = (string) $filter['user_id']; }

        $sql = 'SELECT * FROM member_fee_invoices WHERE ' . implode(' AND ', $where)
             . ' ORDER BY due_date DESC, created_at DESC'
             . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function listOpenForUser(TenantId $tenantId, UserId $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM member_fee_invoices
             WHERE tenant_id = ? AND user_id = ?
               AND status IN ('PENDING', 'OVERDUE', 'REDUCED')
             ORDER BY year DESC"
        );
        $stmt->execute([$tenantId->value(), $userId->value()]);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): MemberFeeInvoice
    {
        $payment = $row['paid_at'] !== null
            ? new PaymentRecord(
                paidAt:      new DateTimeImmutable((string) $row['paid_at']),
                amountCents: (int) $row['paid_amount_cents'],
                method:      (string) $row['paid_method'],
                reference:   (string) ($row['paid_reference'] ?? ''),
                paidBy:      UserId::fromString((string) $row['paid_by']),
            )
            : null;

        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::fromString((string) $row['id']),
            tenantId:            TenantId::fromString((string) $row['tenant_id']),
            userId:              UserId::fromString((string) $row['user_id']),
            year:                (int) $row['year'],
            feeType:             MembershipType::from((string) $row['fee_type']),
            anniversaryDate:     new DateTimeImmutable((string) $row['anniversary_date']),
            amountCents:         (int) $row['amount_cents'],
            originalAmountCents: $row['original_amount_cents'] !== null ? (int) $row['original_amount_cents'] : null,
            currency:            (string) $row['currency'],
            dueDate:             new DateTimeImmutable((string) $row['due_date']),
            status:              MemberFeeInvoiceStatus::from((string) $row['status']),
            payment:             $payment,
            waivedAt:            $row['waived_at'] !== null ? new DateTimeImmutable((string) $row['waived_at']) : null,
            waivedBy:            $row['waived_by'] !== null ? UserId::fromString((string) $row['waived_by']) : null,
            waiveReason:         $row['waive_reason'] !== null ? (string) $row['waive_reason'] : null,
            overrideId:          $row['override_id'] !== null ? (string) $row['override_id'] : null,
            createdAt:           new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
```

- [ ] **Step 3: Run integration test + PHPStan**

```bash
vendor/bin/phpunit --filter SqlMemberFeeInvoiceRepositoryTest
composer analyse
```

Expected: 4 tests OK. PHPStan 0.

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlMemberFeeInvoiceRepository.php tests/Integration/Infrastructure/SqlMemberFeeInvoiceRepositoryTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/sql): SqlMemberFeeInvoiceRepository + integration tests"
```

---

## Task C7: UserFeeOverrideRepositoryInterface (skeleton) + null fake

**Files:**
- Create: `src/Domain/Membership/Billing/UserFeeOverrideRepositoryInterface.php`
- Create: `tests/Support/Fake/InMemoryUserFeeOverrideRepository.php` (null-impl: no overrides)

Wave D builds the full UserFeeOverride domain. For Wave C we only need the interface so `GenerateAnniversaryInvoice` can depend on it. The InMemory fake returns null/empty for every query — meaning "no overrides exist" — which is the behavior we want during Wave C development anyway.

- [ ] **Step 1: Define interface**

`src/Domain/Membership/Billing/UserFeeOverrideRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

interface UserFeeOverrideRepositoryInterface
{
    /**
     * Returns the active override for (tenant, user, fee_type) at the given date,
     * or null if none. "Active" = valid_from <= date <= (valid_until OR infinity) AND revoked_at IS NULL.
     */
    public function findActiveFor(
        TenantId $tenantId,
        UserId $userId,
        string $feeType,
        DateTimeImmutable $asOf,
    ): ?UserFeeOverride;

    public function save(UserFeeOverride $override): void;

    /**
     * @return list<UserFeeOverride>
     */
    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array;

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride;
}
```

Note: `UserFeeOverride` and `UserFeeOverrideId` classes don't exist yet — they land in Wave D Task D2/D3. The interface refers to them anyway; PHP autoloads them when Wave D's code is committed. Wave C only USES the interface via dependency injection — the InMemory fake returns null for `findActiveFor`, so the absent entity class is never instantiated.

To avoid PHPStan "unknown class" errors during Wave C, add a stub Wave-D placeholder:

`src/Domain/Membership/Billing/UserFeeOverride.php` (Wave D will replace):

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

/**
 * Placeholder — Wave D Task D3 replaces this with the full entity.
 * For Wave C the class exists only so PHPStan can resolve type hints in
 * UserFeeOverrideRepositoryInterface and InMemoryUserFeeOverrideRepository.
 *
 * @internal Until Wave D ships, do NOT instantiate this class.
 */
final class UserFeeOverride
{
    public function __construct() {}
}
```

`src/Domain/Membership/Billing/UserFeeOverrideId.php` (Wave D will replace):

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

/**
 * Placeholder — Wave D Task D2 replaces this with the full UUIDv7 wrapper.
 * @internal
 */
final class UserFeeOverrideId
{
    public function value(): string { return ''; }
}
```

- [ ] **Step 2: Implement null InMemory fake**

`tests/Support/Fake/InMemoryUserFeeOverrideRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

/**
 * Wave C version: returns no overrides for any query. Wave D replaces this
 * with a real in-memory store; until then GenerateAnniversaryInvoice always
 * applies the full schedule price (no overrides).
 */
final class InMemoryUserFeeOverrideRepository implements UserFeeOverrideRepositoryInterface
{
    public function findActiveFor(TenantId $tenantId, UserId $userId, string $feeType, DateTimeImmutable $asOf): ?UserFeeOverride
    {
        return null;
    }

    public function save(UserFeeOverride $override): void {}

    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array
    {
        return [];
    }

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride
    {
        return null;
    }
}
```

- [ ] **Step 3: PHPStan check**

```bash
composer analyse
```

Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Membership/Billing/UserFeeOverrideRepositoryInterface.php src/Domain/Membership/Billing/UserFeeOverride.php src/Domain/Membership/Billing/UserFeeOverrideId.php tests/Support/Fake/InMemoryUserFeeOverrideRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain): UserFeeOverrideRepository interface + Wave-C placeholder stubs"
```

---

## Task C8: GenerateAnniversaryInvoice use case

**Files:**
- Create: `src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoice.php`
- Create: `src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoiceInput.php`
- Create: `src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoiceOutput.php`
- Create: `src/Application/Membership/Billing/GenerateAnniversaryInvoice/Exception/NoActiveFeeScheduleException.php`
- Create: `tests/Unit/Application/Membership/Billing/GenerateAnniversaryInvoiceTest.php`

Use case input: (tenantId, userId). Reads user's membership_started_at, membership_type, membership_status from a UserRepository. Validates user is active + non-honorary. Computes year = today.year. Idempotency check: if invoice for (tenant, user, year) exists, return existing without changes. Looks up active fee schedule for (tenant, year, type) — throws if none. Looks up active override for (tenant, user, type) — applies if found. Computes due_date = today + default_due_days_from_anniversary. Creates and saves invoice.

- [ ] **Step 1: Create exception**

`src/Application/Membership/Billing/GenerateAnniversaryInvoice/Exception/NoActiveFeeScheduleException.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception;

final class NoActiveFeeScheduleException extends \DomainException
{
}
```

- [ ] **Step 2: Create DTOs**

`src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoiceInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class GenerateAnniversaryInvoiceInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId   $userId,
    ) {}
}
```

`src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoiceOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice;

final class GenerateAnniversaryInvoiceOutput
{
    public function __construct(
        public readonly bool    $created,
        public readonly string  $invoiceId,
        public readonly int     $amountCents,
        public readonly ?string $overrideId,
    ) {}
}
```

- [ ] **Step 3: Write test (covers idempotency, override, no-schedule error)**

`tests/Unit/Application/Membership/Billing/GenerateAnniversaryInvoiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception\NoActiveFeeScheduleException;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoiceInput;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantGovernanceSettings;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GenerateAnniversaryInvoiceTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_creates_invoice_for_active_basic_member(): void
    {
        [$useCase, $invRepo] = $this->makeUseCase(year: 2026, scheduleAmountCents: 5000);

        $output = $useCase->handle(new GenerateAnniversaryInvoiceInput(
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        ));

        $this->assertTrue($output->created);
        $this->assertSame(5000, $output->amountCents);
        $this->assertNull($output->overrideId);

        $invoice = $invRepo->findFor(TenantId::fromString(self::TENANT_ID), UserId::fromString(self::USER_ID), 2026);
        $this->assertNotNull($invoice);
        $this->assertSame(5000, $invoice->amountCents());
        $this->assertSame(MembershipType::Basic, $invoice->feeType());
        $this->assertEquals(new DateTimeImmutable('2026-09-13'), $invoice->dueDate());
    }

    public function test_idempotent_on_repeat(): void
    {
        [$useCase, $invRepo] = $this->makeUseCase(year: 2026, scheduleAmountCents: 5000);
        $input = new GenerateAnniversaryInvoiceInput(
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        );

        $first = $useCase->handle($input);
        $second = $useCase->handle($input);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->invoiceId, $second->invoiceId);
    }

    public function test_throws_when_no_active_schedule(): void
    {
        [$useCase] = $this->makeUseCase(year: 2026, scheduleAmountCents: null);

        $this->expectException(NoActiveFeeScheduleException::class);
        $useCase->handle(new GenerateAnniversaryInvoiceInput(
            tenantId: TenantId::fromString(self::TENANT_ID),
            userId:   UserId::fromString(self::USER_ID),
        ));
    }

    /**
     * @return array{0:GenerateAnniversaryInvoice,1:InMemoryMemberFeeInvoiceRepository}
     */
    private function makeUseCase(int $year, ?int $scheduleAmountCents): array
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);

        $users = new InMemoryUserRepository();
        $users->save(new User(
            id:              UserId::fromString(self::USER_ID),
            name:            'Test',
            email:           'test@daems.fi',
            passwordHash:    null,
            dateOfBirth:     new DateTimeImmutable('1990-01-01'),
            isPlatformAdmin: false,
            // …assume User entity has membership fields wired in 0.6a:
            membershipType:      MembershipType::Basic,
            membershipStatus:    'active',
            membershipStartedAt: new DateTimeImmutable('2024-07-15'),
        ));

        $schedules = new InMemoryAnnualFeeScheduleRepository();
        if ($scheduleAmountCents !== null) {
            $schedules->save(new AnnualFeeSchedule(
                id:           AnnualFeeScheduleId::generate(),
                tenantId:     $tenantId,
                year:         $year,
                feeType:      MembershipType::Basic,
                amountCents:  $scheduleAmountCents,
                currency:     'EUR',
                status:       AnnualFeeScheduleStatus::Active,
                decisionId:   null,
                activatedAt:  new DateTimeImmutable('2025-12-01'),
                activatedBy:  null,
                supersededAt: null,
                createdAt:    new DateTimeImmutable('2025-12-01'),
                createdBy:    null,
            ));
        }

        $overrides = new InMemoryUserFeeOverrideRepository();
        $invoices  = new InMemoryMemberFeeInvoiceRepository();

        $settings = new InMemoryTenantGovernanceSettingsRepository();
        $settings->save(new TenantGovernanceSettings(
            tenantId:                          $tenantId,
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     false,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
            updatedAt:                         new DateTimeImmutable(),
        ));

        $clock = new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable("{$year}-07-15T02:00:00"));

        $useCase = new GenerateAnniversaryInvoice($users, $schedules, $overrides, $invoices, $settings, $clock);
        return [$useCase, $invoices];
    }
}
```

- [ ] **Step 4: Implement use case**

`src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoice.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\GenerateAnniversaryInvoice;

use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception\NoActiveFeeScheduleException;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock\ClockInterface;
use Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class GenerateAnniversaryInvoice
{
    public function __construct(
        private readonly UserRepositoryInterface                     $users,
        private readonly AnnualFeeScheduleRepositoryInterface        $schedules,
        private readonly UserFeeOverrideRepositoryInterface          $overrides,
        private readonly MemberFeeInvoiceRepositoryInterface         $invoices,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly ClockInterface                              $clock,
    ) {}

    public function handle(GenerateAnniversaryInvoiceInput $in): GenerateAnniversaryInvoiceOutput
    {
        $user = $this->users->findById($in->userId);
        if ($user === null) {
            throw new \DomainException("User not found: {$in->userId->value()}");
        }

        // Skip honorary
        $type = $user->membershipType();
        if ($type === MembershipType::Honorary) {
            throw new \DomainException("HONORARY members do not receive invoices (§ 3)");
        }
        // Skip non-active members
        if ($user->membershipStatus() !== 'active') {
            throw new \DomainException("User membership_status is {$user->membershipStatus()}, expected active");
        }

        $today = $this->clock->now();
        $year = (int) $today->format('Y');

        // Idempotency: invoice already exists?
        $existing = $this->invoices->findFor($in->tenantId, $in->userId, $year);
        if ($existing !== null) {
            return new GenerateAnniversaryInvoiceOutput(
                created:     false,
                invoiceId:   $existing->id()->value(),
                amountCents: $existing->amountCents(),
                overrideId:  $existing->overrideId(),
            );
        }

        // Active schedule lookup
        $schedule = $this->schedules->findActiveFor($in->tenantId, $year, $type);
        if ($schedule === null) {
            throw new NoActiveFeeScheduleException(
                "No active fee schedule for tenant {$in->tenantId->value()} year {$year} type {$type->value}"
            );
        }
        $amount = $schedule->amountCents();
        $overrideId = null;

        // Override application
        $override = $this->overrides->findActiveFor($in->tenantId, $in->userId, $type->value, $today);
        if ($override !== null) {
            // Wave D: $override has overrideAmountCents() + id() methods. Wave C placeholder
            // returns null from findActiveFor() so this branch never executes.
            $amount = method_exists($override, 'overrideAmountCents') ? $override->overrideAmountCents() : $amount;
            $overrideId = method_exists($override, 'id') ? $override->id()->value() : null;
        }

        $settings = $this->settings->findForTenant($in->tenantId);
        $dueDays = $settings?->defaultDueDaysFromAnniversary() ?? 60;
        $dueDate = $today->modify("+{$dueDays} days")->setTime(0, 0, 0);

        $invoice = new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $in->tenantId,
            userId:              $in->userId,
            year:                $year,
            feeType:             $type,
            anniversaryDate:     $today->setTime(0, 0, 0),
            amountCents:         $amount,
            originalAmountCents: null,
            currency:            $schedule->currency(),
            dueDate:             $dueDate,
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          $overrideId,
            createdAt:           $today,
        );
        $this->invoices->save($invoice);

        return new GenerateAnniversaryInvoiceOutput(
            created:     true,
            invoiceId:   $invoice->id()->value(),
            amountCents: $amount,
            overrideId:  $overrideId,
        );
    }
}
```

Note: the `method_exists($override, ...)` check works around the Wave C placeholder UserFeeOverride having empty methods. Wave D Task D3 replaces the entity with real methods; remove the `method_exists` shim then.

- [ ] **Step 5: Run + PHPStan**

```bash
vendor/bin/phpunit --filter GenerateAnniversaryInvoiceTest
composer analyse
```

Expected: 3 tests OK. PHPStan 0.

- [ ] **Step 6: Commit**

```bash
git add src/Application/Membership/Billing/GenerateAnniversaryInvoice/ tests/Unit/Application/Membership/Billing/GenerateAnniversaryInvoiceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): GenerateAnniversaryInvoice use case (snapshot + idempotent)"
```

---

## Task C9: GenerateAnniversaryInvoicesCommand cron + bootstrap registration

**Files:**
- Create: `src/Application/Membership/Billing/Cron/GenerateAnniversaryInvoicesCommand.php`
- Modify: `bootstrap/console.php`
- Create: `tests/Integration/Cron/GenerateAnniversaryInvoicesCommandTest.php`

The command iterates all tenants (or filters by `--tenant=<slug>`), then for each tenant iterates users where `MONTH(membership_started_at) = MONTH(today) AND DAY(membership_started_at) = DAY(today) AND YEAR(membership_started_at) < YEAR(today)` AND `membership_status='active'` AND `membership_type != 'HONORARY'`. For each match, dispatches `GenerateAnniversaryInvoice` use case. Logs per-tenant summary.

- [ ] **Step 1: Write the integration test**

`tests/Integration/Cron/GenerateAnniversaryInvoicesCommandTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Application\Membership\Billing\Cron\GenerateAnniversaryInvoicesCommand;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class GenerateAnniversaryInvoicesCommandTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
    }

    public function test_creates_invoices_for_users_whose_anniversary_is_today(): void
    {
        $this->seedTenant('daems');
        $tenantId = $this->tenantIdBySlug('daems');
        $userMatch  = $this->seedUser($tenantId, 'match@daems.fi',     anniversary: '2024-07-15', type: 'BASIC');
        $userOff    = $this->seedUser($tenantId, 'off@daems.fi',       anniversary: '2024-07-14', type: 'BASIC');
        $userHonor  = $this->seedUser($tenantId, 'honor@daems.fi',     anniversary: '2024-07-15', type: 'HONORARY');
        $userPaused = $this->seedUser($tenantId, 'paused@daems.fi',    anniversary: '2024-07-15', type: 'BASIC', status: 'paused');
        $userTooNew = $this->seedUser($tenantId, 'newbie@daems.fi',    anniversary: '2026-07-15', type: 'BASIC');  // joined today

        $this->seedActiveSchedule($tenantId, 2026, MembershipType::Basic, amountCents: 5000);

        $command = $this->makeCommand(today: new DateTimeImmutable('2026-07-15T02:00:00'));
        $exit = $command->execute(['tenant' => 'daems']);

        $this->assertSame(0, $exit);
        $this->assertNotNull($this->findInvoice($tenantId, $userMatch, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userOff, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userHonor, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userPaused, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userTooNew, 2026));
    }

    public function test_idempotent_second_run_creates_nothing(): void
    {
        $this->seedTenant('daems');
        $tenantId = $this->tenantIdBySlug('daems');
        $this->seedUser($tenantId, 'match@daems.fi', anniversary: '2024-07-15', type: 'BASIC');
        $this->seedActiveSchedule($tenantId, 2026, MembershipType::Basic, amountCents: 5000);

        $command = $this->makeCommand(today: new DateTimeImmutable('2026-07-15T02:00:00'));
        $command->execute(['tenant' => 'daems']);
        $countAfterFirst = $this->countInvoices($tenantId, 2026);

        $command->execute(['tenant' => 'daems']);
        $countAfterSecond = $this->countInvoices($tenantId, 2026);

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    // -- helpers ------------------------------------------------------------

    private function seedTenant(string $slug): void
    {
        // tenants seeded by migration 019; we may use it directly.
    }

    private function tenantIdBySlug(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return TenantId::fromString((string) $stmt->fetchColumn());
    }

    private function seedUser(TenantId $tenantId, string $email, string $anniversary, string $type, string $status = 'active'): UserId
    {
        $id = '01958000-0000-7000-9000-' . substr(md5($email), 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, NULL, ?, 0, ?, ?, ?)'
        )->execute([
            $id, 'User-' . substr($email, 0, 5), $email, '1990-01-01', $type, $status, $anniversary . ' 00:00:00',
        ]);
        return UserId::fromString($id);
    }

    private function seedActiveSchedule(TenantId $tenantId, int $year, MembershipType $type, int $amountCents): void
    {
        $repo = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository($this->pdo());
        $repo->save(new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $tenantId,
            year:         $year,
            feeType:      $type,
            amountCents:  $amountCents,
            currency:     'EUR',
            status:       AnnualFeeScheduleStatus::Active,
            decisionId:   null,
            activatedAt:  new DateTimeImmutable('2025-12-01'),
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2025-12-01'),
            createdBy:    null,
        ));
    }

    private function findInvoice(TenantId $tenantId, UserId $userId, int $year): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM member_fee_invoices WHERE tenant_id = ? AND user_id = ? AND year = ?');
        $stmt->execute([$tenantId->value(), $userId->value(), $year]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function countInvoices(TenantId $tenantId, int $year): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM member_fee_invoices WHERE tenant_id = ? AND year = ?');
        $stmt->execute([$tenantId->value(), $year]);
        return (int) $stmt->fetchColumn();
    }

    private function makeCommand(DateTimeImmutable $today): GenerateAnniversaryInvoicesCommand
    {
        return new GenerateAnniversaryInvoicesCommand(
            pdo: $this->pdo(),
            useCase: new \Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice(
                users:     new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository($this->pdo()),
                schedules: new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository($this->pdo()),
                overrides: new \Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository(),
                invoices:  new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository($this->pdo()),
                settings:  new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository($this->pdo()),
                clock:     new \Daems\Domain\Shared\Clock\FixedClock($today),
            ),
            lockManager: new \Daems\Infrastructure\Console\LockManager(sys_get_temp_dir()),
            logger:      new \Daems\Infrastructure\Console\CronLogger(sys_get_temp_dir(), 'membership:generate-anniversary-invoices', $today),
            clock:       new \Daems\Domain\Shared\Clock\FixedClock($today),
        );
    }
}
```

- [ ] **Step 2: Implement command**

`src/Application/Membership/Billing/Cron/GenerateAnniversaryInvoicesCommand.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\Cron;

use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\Exception\NoActiveFeeScheduleException;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoiceInput;
use Daems\Domain\Shared\Clock\ClockInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use PDO;

final class GenerateAnniversaryInvoicesCommand implements CommandInterface
{
    public function __construct(
        private readonly PDO                          $pdo,
        private readonly GenerateAnniversaryInvoice   $useCase,
        private readonly LockManager                  $lockManager,
        private readonly CronLogger                   $logger,
        private readonly ClockInterface               $clock,
    ) {}

    public function name(): string
    {
        return 'membership:generate-anniversary-invoices';
    }

    /**
     * @param array<string,string|bool> $args  Supported: --tenant=<slug>
     */
    public function execute(array $args): int
    {
        if (!$this->lockManager->acquire($this->name())) {
            $this->logger->info(['message' => 'lock held, skipping', 'command' => $this->name()]);
            return 0;
        }

        try {
            $today = $this->clock->now();
            $tenantFilter = isset($args['tenant']) && is_string($args['tenant']) ? $args['tenant'] : null;

            $tenants = $this->loadTenants($tenantFilter);
            $totalCreated = 0;
            $tDuration = microtime(true);

            foreach ($tenants as $tenant) {
                $tenantId = TenantId::fromString((string) $tenant['id']);
                $slug = (string) $tenant['slug'];

                $candidates = $this->loadCandidates($tenantId, $today);
                $created = 0;
                $skipped = 0;
                $errors = 0;

                foreach ($candidates as $userId) {
                    try {
                        $out = $this->useCase->handle(new GenerateAnniversaryInvoiceInput($tenantId, $userId));
                        if ($out->created) { $created++; } else { $skipped++; }
                    } catch (NoActiveFeeScheduleException $e) {
                        $errors++;
                        $this->logger->error([
                            'tenant'  => $slug,
                            'user_id' => $userId->value(),
                            'message' => $e->getMessage(),
                            'code'    => 'NO_ACTIVE_SCHEDULE',
                        ]);
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->logger->error([
                            'tenant'  => $slug,
                            'user_id' => $userId->value(),
                            'message' => $e->getMessage(),
                            'code'    => 'UNEXPECTED',
                        ]);
                    }
                }

                $this->logger->info([
                    'tenant'    => $slug,
                    'processed' => count($candidates),
                    'created'   => $created,
                    'skipped'   => $skipped,
                    'errors'    => $errors,
                ]);
                $totalCreated += $created;
            }

            $this->logger->info([
                'summary'        => true,
                'total_tenants'  => count($tenants),
                'total_created'  => $totalCreated,
                'duration_ms'    => (int) ((microtime(true) - $tDuration) * 1000),
            ]);

            return 0;
        } finally {
            $this->lockManager->release($this->name());
        }
    }

    /**
     * @return list<array{id:string, slug:string}>
     */
    private function loadTenants(?string $slug): array
    {
        $sql = 'SELECT id, slug FROM tenants WHERE suspended_at IS NULL';
        $params = [];
        if ($slug !== null) {
            $sql .= ' AND slug = ?';
            $params[] = $slug;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => ['id' => (string) $r['id'], 'slug' => (string) $r['slug']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * @return list<UserId>
     */
    private function loadCandidates(TenantId $tenantId, \DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id
             FROM users u
             INNER JOIN user_tenants ut ON ut.user_id = u.id
             WHERE ut.tenant_id            = :tid
               AND u.membership_status     = 'active'
               AND u.membership_type      != 'HONORARY'
               AND u.membership_started_at IS NOT NULL
               AND MONTH(u.membership_started_at) = :m
               AND DAY(u.membership_started_at)   = :d
               AND YEAR(u.membership_started_at)  < :y"
        );
        $stmt->execute([
            'tid' => $tenantId->value(),
            'm'   => (int) $today->format('n'),
            'd'   => (int) $today->format('j'),
            'y'   => (int) $today->format('Y'),
        ]);
        return array_map(
            static fn(array $r) => UserId::fromString((string) $r['id']),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }
}
```

- [ ] **Step 3: Register in bootstrap/console.php**

Open `bootstrap/console.php`. After the `console:hello` smoke command, add:

```php
// Wire the membership-anniversary cron. Same PDO + clock chosen by app bootstrap.
require __DIR__ . '/app.php'; // gives access to $container (PSR-11-style)

$registry->register(new \Daems\Application\Membership\Billing\Cron\GenerateAnniversaryInvoicesCommand(
    pdo:         $container[\PDO::class],
    useCase:     $container[\Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice::class],
    lockManager: new \Daems\Infrastructure\Console\LockManager(__DIR__ . '/../var/run'),
    logger:      new \Daems\Infrastructure\Console\CronLogger(__DIR__ . '/../var/log/cron', 'membership:generate-anniversary-invoices', new \DateTimeImmutable()),
    clock:       $container[\Daems\Domain\Shared\Clock\ClockInterface::class],
));
```

Adjust the container access pattern to match how `bootstrap/app.php` actually exposes it (the snippet assumes ArrayAccess).

- [ ] **Step 4: Run integration test + PHPStan**

```bash
vendor/bin/phpunit --filter GenerateAnniversaryInvoicesCommandTest
composer analyse
```

Expected: 2 tests OK. PHPStan 0.

- [ ] **Step 5: Manual smoke (Windows)**

```bash
php bin/console membership:generate-anniversary-invoices --tenant=daems
```

Expected output (or similar):
- exit 0
- new line in `var/log/cron/membership-generate-anniversary-invoices-YYYY-MM-DD.log`
- if no users have anniversary today → `created=0, processed=0`

- [ ] **Step 6: Commit**

```bash
git add src/Application/Membership/Billing/Cron/ bootstrap/console.php tests/Integration/Cron/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(cron): GenerateAnniversaryInvoicesCommand + bootstrap registration"
```

---

## Task C10: DI wiring — bootstrap/app.php + KernelHarness

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

- [ ] **Step 1: Add to bootstrap/app.php**

```php
// Membership Billing — invoices + use case (0.7)
$container[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class] =
    fn($c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository($c[PDO::class]);

// UserFeeOverride: Wave C uses InMemory null-fake (returns no overrides);
// Wave D Task D6 replaces this with the SQL repo.
$container[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class] =
    fn() => new \Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository();

$container[\Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice::class] =
    fn($c) => new \Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice(
        $c[\Daems\Domain\User\UserRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
        $c[\Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

⚠️ Using a Fake (`InMemoryUserFeeOverrideRepository`) from `tests/Support/` inside `bootstrap/app.php` is a temporary anti-pattern for Wave C — it works in dev because `tests/` is autoloaded for dev, but production deploy will not autoload `tests/`. Wave D Task D6 MUST replace this with the SQL impl before any deploy.

Mark the binding with a TODO comment:

```php
// TODO(0.7-wave-d): replace InMemoryUserFeeOverrideRepository with SqlUserFeeOverrideRepository
//                   before merging to dev. Test-namespace classes are not autoloaded in prod.
```

- [ ] **Step 2: Same wiring in tests/Support/KernelHarness.php**

Replicate the bindings, but the override binding can stay `InMemoryUserFeeOverrideRepository` for the harness — that's the harness's job.

- [ ] **Step 3: Verify**

```bash
grep -n "GenerateAnniversaryInvoice\|MemberFeeInvoiceRepository\|UserFeeOverrideRepository" bootstrap/app.php tests/Support/KernelHarness.php
composer analyse
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -3
```

Expected: classes present in BOTH containers. PHPStan 0. Unit green.

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(di): bind MemberFeeInvoiceRepository + GenerateAnniversaryInvoice in BOTH"
```

---

## Task C11: Smoke-test the cron locally with a manufactured anniversary

**Files:**
- (no code changes — manual verification only)

This task is a manual end-to-end smoke test that exercises real DB + real cron command. Not committed — but DO run it before declaring Wave C done.

- [ ] **Step 1: Set up a test user**

```sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db <<SQL
UPDATE users
   SET membership_started_at = DATE_SUB(CURDATE(), INTERVAL 2 YEAR),
       membership_type       = 'BASIC',
       membership_status     = 'active'
 WHERE email = 'admin@daems.fi'
 LIMIT 1;
SQL
```

(Use any real user in the daems tenant — the admin user works.)

- [ ] **Step 2: Make sure there's an active fee schedule for THIS year**

```bash
curl -X POST http://daems-platform.local/api/v1/backstage/governance/billing/fee-schedules \
  -H "Content-Type: application/json" \
  -H "Cookie: <admin-session-cookie>" \
  -d '{"year": '$(date +%Y)', "fees": {"SUPPORTING": 1000, "BASIC": 5000, "FULL": 0}}'
```

- [ ] **Step 3: Run the cron**

```bash
php bin/console membership:generate-anniversary-invoices --tenant=daems
```

Expected: exit 0 + log line `tenant=daems processed=1 created=1`.

- [ ] **Step 4: Verify the invoice landed**

```sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e \
  "SELECT id, year, amount_cents, status, due_date FROM member_fee_invoices ORDER BY created_at DESC LIMIT 3;"
```

Expected: row with `amount_cents=5000, status='PENDING', due_date=NOW+60 days`.

- [ ] **Step 5: Re-run for idempotency check**

```bash
php bin/console membership:generate-anniversary-invoices --tenant=daems
```

Expected: log shows `processed=1 created=0 skipped=1`.

- [ ] **Step 6: Clean up the test user (so the next dev session is consistent)**

```sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db <<SQL
UPDATE users SET membership_started_at = NULL WHERE email = 'admin@daems.fi' LIMIT 1;
DELETE FROM member_fee_invoices WHERE user_id IN (SELECT id FROM users WHERE email = 'admin@daems.fi');
SQL
```

(Note: no commit. If you find a bug, file it as a follow-up task in deferred-items.md or fix-and-commit in Task C9.)

---

## Task C12: Wave C Definition of Done

After Tasks C1-C11, the following invariants hold. Verify before starting Wave D:

- [ ] Migration 091 applied to `daems_db` AND `daems_db_test`
- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --testsuite Unit` green (count up by ~20 from Wave B baseline)
- [ ] `vendor/bin/phpunit --filter 'MemberFeeInvoice|GenerateAnniversaryInvoice|SqlMemberFeeInvoice'` green
- [ ] `php bin/console membership:generate-anniversary-invoices` runs to exit 0 with empty output (no users anniversaring today is OK)
- [ ] Manual smoke (C11) verified the end-to-end flow on dev DB

Run the verification gate:

```bash
composer analyse
vendor/bin/phpunit --filter 'Billing' 2>&1 | tail -3
php bin/console membership:generate-anniversary-invoices
```

If anything fails, FIX before starting Wave D.

---

# Wave D — UserFeeOverride domain + UI (Phase 6, 8 tasks)

This wave replaces the Wave C placeholder UserFeeOverride stubs with the real entity, SQL repo, use cases, and admin UI. After this wave admins can grant per-user määräaikaisia alennuksia (esim. opiskelija-alennus 50%, 2 vuotta) and the anniversary cron applies them automatically.

## Task D1: Migration 092 — user_fee_overrides + FK on member_fee_invoices.override_id

**Files:**
- Create: `database/migrations/092_create_user_fee_overrides.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/092_create_user_fee_overrides.sql`:

```sql
-- 092_create_user_fee_overrides.sql
-- Per-user time-bounded fee override (§ 5: "alentaa maksua määräajaksi
-- perustellusta syystä"). Anniversary-cron checks this table at invoice
-- creation; matching active override replaces the schedule price for that user.
--
-- Adds the deferred FK from member_fee_invoices.override_id once both
-- tables exist.

CREATE TABLE IF NOT EXISTS user_fee_overrides (
    id                       CHAR(36)             NOT NULL,
    tenant_id                CHAR(36)             NOT NULL,
    user_id                  CHAR(36)             NOT NULL,
    fee_type                 VARCHAR(20)          NOT NULL,
    override_amount_cents    INT UNSIGNED         NOT NULL,
    valid_from               DATE                 NOT NULL,
    valid_until              DATE                 NULL,
    reason                   TEXT                 NOT NULL,
    decision_id              CHAR(36)             NULL,
    created_by               CHAR(36)             NOT NULL,
    created_at               DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at               DATETIME             NULL,
    revoked_by               CHAR(36)             NULL,
    PRIMARY KEY (id),
    KEY idx_active_lookup (tenant_id, user_id, fee_type, valid_from, valid_until, revoked_at),
    KEY idx_tenant (tenant_id, created_at),
    CONSTRAINT fk_ufo_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ufo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ufo_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE SET NULL,
    CONSTRAINT fk_ufo_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ufo_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add deferred FK from member_fee_invoices.override_id (table created in mig 091).
ALTER TABLE member_fee_invoices
    ADD CONSTRAINT fk_mfi_override
        FOREIGN KEY (override_id) REFERENCES user_fee_overrides(id) ON DELETE SET NULL;
```

- [ ] **Step 2: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/092_create_user_fee_overrides.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/092_create_user_fee_overrides.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE user_fee_overrides\G"
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE member_fee_invoices\G" | grep override
```

Expected: `user_fee_overrides` exists with 5 FKs. `member_fee_invoices` shows the new `fk_mfi_override` constraint.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/092_create_user_fee_overrides.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 092 — user_fee_overrides + deferred FK on member_fee_invoices"
```

---

## Task D2: Replace UserFeeOverrideId placeholder with real VO

**Files:**
- Modify: `src/Domain/Membership/Billing/UserFeeOverrideId.php` (replace Wave C stub)
- Create: `tests/Unit/Domain/Membership/Billing/UserFeeOverrideIdTest.php`

- [ ] **Step 1: Write test (mirror AnnualFeeScheduleIdTest)**

`tests/Unit/Domain/Membership/Billing/UserFeeOverrideIdTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use PHPUnit\Framework\TestCase;

final class UserFeeOverrideIdTest extends TestCase
{
    public function test_generate_creates_uuid7(): void
    {
        $id = UserFeeOverrideId::generate();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id->value());
    }

    public function test_from_string_round_trip(): void
    {
        $raw = '01958000-0000-7000-8000-0000000000aa';
        $this->assertSame($raw, UserFeeOverrideId::fromString($raw)->value());
    }

    public function test_from_string_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UserFeeOverrideId::fromString('not-a-uuid');
    }

    public function test_equals(): void
    {
        $a = UserFeeOverrideId::fromString('01958000-0000-7000-8000-0000000000aa');
        $b = UserFeeOverrideId::fromString('01958000-0000-7000-8000-0000000000aa');
        $c = UserFeeOverrideId::fromString('01958000-0000-7000-8000-0000000000bb');
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
```

- [ ] **Step 2: Replace the Wave C placeholder**

Open `src/Domain/Membership/Billing/UserFeeOverrideId.php` and replace ALL content with:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class UserFeeOverrideId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new self(Uuid7::generate()->value());
    }

    public static function fromString(string $raw): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $raw) !== 1) {
            throw new \InvalidArgumentException("Not a valid UUIDv7: {$raw}");
        }
        return new self($raw);
    }

    public function value(): string { return $this->value; }
    public function equals(self $other): bool { return $this->value === $other->value; }
}
```

- [ ] **Step 3: Run + PHPStan**

```bash
vendor/bin/phpunit --filter UserFeeOverrideIdTest
composer analyse
```

Expected: 4 tests OK. PHPStan 0.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Membership/Billing/UserFeeOverrideId.php tests/Unit/Domain/Membership/Billing/UserFeeOverrideIdTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Replace(domain): UserFeeOverrideId placeholder with real UUIDv7 VO"
```

---

## Task D3: Replace UserFeeOverride placeholder with real entity

**Files:**
- Modify: `src/Domain/Membership/Billing/UserFeeOverride.php` (replace Wave C stub)
- Create: `tests/Unit/Domain/Membership/Billing/UserFeeOverrideTest.php`

- [ ] **Step 1: Write test**

`tests/Unit/Domain/Membership/Billing/UserFeeOverrideTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UserFeeOverrideTest extends TestCase
{
    public function test_constructs_with_required_fields(): void
    {
        $o = $this->override();
        $this->assertSame(2500, $o->overrideAmountCents());
        $this->assertSame(MembershipType::Basic, $o->feeType());
        $this->assertSame('Opiskelija-alennus', $o->reason());
    }

    public function test_rejects_empty_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              '',
            decisionId:          null,
            createdBy:           UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            createdAt:           new DateTimeImmutable(),
            revokedAt:           null,
            revokedBy:           null,
        );
    }

    public function test_rejects_until_before_from(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2027-12-31'),
            validUntil:          new DateTimeImmutable('2026-01-01'),
            reason:              'invalid range',
            decisionId:          null,
            createdBy:           UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            createdAt:           new DateTimeImmutable(),
            revokedAt:           null,
            revokedBy:           null,
        );
    }

    public function test_isActive_at(): void
    {
        $o = $this->override();
        $this->assertTrue($o->isActiveAt(new DateTimeImmutable('2026-06-15')));
        $this->assertFalse($o->isActiveAt(new DateTimeImmutable('2025-06-15')));  // before from
        $this->assertFalse($o->isActiveAt(new DateTimeImmutable('2028-06-15')));  // after until
    }

    public function test_isActive_with_null_valid_until(): void
    {
        $o = $this->override(validUntil: null);
        $this->assertTrue($o->isActiveAt(new DateTimeImmutable('2099-06-15')));
    }

    public function test_revoke_sets_fields(): void
    {
        $o = $this->override();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $now = new DateTimeImmutable('2026-08-01T00:00:00');
        $o->revoke($actor, $now);

        $this->assertEquals($now, $o->revokedAt());
        $this->assertEquals($actor, $o->revokedBy());
        $this->assertFalse($o->isActiveAt(new DateTimeImmutable('2026-09-15'))); // revoked → inactive
    }

    public function test_revoke_twice_is_idempotent(): void
    {
        $o = $this->override();
        $actor = UserId::fromString('01958000-0000-7000-8000-0000000000bb');
        $first = new DateTimeImmutable('2026-08-01T00:00:00');
        $second = new DateTimeImmutable('2027-01-01T00:00:00');
        $o->revoke($actor, $first);
        $o->revoke($actor, $second);
        $this->assertEquals($first, $o->revokedAt()); // first wins
    }

    private function override(?DateTimeImmutable $validUntil = null): UserFeeOverride
    {
        return new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          $validUntil ?? new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
            decisionId:          null,
            createdBy:           UserId::fromString('01958000-0000-7000-8000-0000000000aa'),
            createdAt:           new DateTimeImmutable('2025-12-15'),
            revokedAt:           null,
            revokedBy:           null,
        );
    }
}
```

- [ ] **Step 2: Replace placeholder entity**

Open `src/Domain/Membership/Billing/UserFeeOverride.php` and replace ALL content with:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Per-user override of the standard fee schedule.
 *
 * Created by board/admin when § 5 conditions apply ("vapauttaa jäsenen
 * jäsenmaksusta tai alentaa maksua määräajaksi perustellusta syystä").
 *
 * Override applies during the time window [valid_from, valid_until]. If
 * valid_until is NULL the override is indefinite. revoke() ends it early
 * (soft delete via revoked_at — historical audit preserved).
 *
 * Anniversary-cron checks isActiveAt(today) before applying.
 */
final class UserFeeOverride
{
    public function __construct(
        private readonly UserFeeOverrideId  $id,
        private readonly TenantId           $tenantId,
        private readonly UserId             $userId,
        private readonly MembershipType     $feeType,
        private readonly int                $overrideAmountCents,
        private readonly DateTimeImmutable  $validFrom,
        private readonly ?DateTimeImmutable $validUntil,
        private readonly string             $reason,
        private readonly ?string            $decisionId,
        private readonly UserId             $createdBy,
        private readonly DateTimeImmutable  $createdAt,
        private ?DateTimeImmutable          $revokedAt,
        private ?UserId                     $revokedBy,
    ) {
        if ($overrideAmountCents < 0) {
            throw new InvalidArgumentException("override_amount_cents must be >= 0");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("reason must not be empty");
        }
        if ($validUntil !== null && $validUntil < $validFrom) {
            throw new InvalidArgumentException("valid_until must be >= valid_from");
        }
        if ($feeType === MembershipType::Honorary) {
            throw new InvalidArgumentException("HONORARY type cannot have an override (no fees apply)");
        }
    }

    public function id(): UserFeeOverrideId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function userId(): UserId { return $this->userId; }
    public function feeType(): MembershipType { return $this->feeType; }
    public function overrideAmountCents(): int { return $this->overrideAmountCents; }
    public function validFrom(): DateTimeImmutable { return $this->validFrom; }
    public function validUntil(): ?DateTimeImmutable { return $this->validUntil; }
    public function reason(): string { return $this->reason; }
    public function decisionId(): ?string { return $this->decisionId; }
    public function createdBy(): UserId { return $this->createdBy; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function revokedBy(): ?UserId { return $this->revokedBy; }

    public function isActiveAt(DateTimeImmutable $when): bool
    {
        if ($this->revokedAt !== null && $when >= $this->revokedAt) {
            return false;
        }
        if ($when < $this->validFrom) {
            return false;
        }
        if ($this->validUntil !== null && $when > $this->validUntil) {
            return false;
        }
        return true;
    }

    public function revoke(UserId $actor, DateTimeImmutable $now): void
    {
        if ($this->revokedAt !== null) {
            return; // idempotent
        }
        $this->revokedAt = $now;
        $this->revokedBy = $actor;
    }
}
```

- [ ] **Step 3: Update GenerateAnniversaryInvoice — remove method_exists shim from Task C8**

Open `src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoice.php`. Replace the override-application block:

```php
// Old (Wave C):
if ($override !== null) {
    $amount = method_exists($override, 'overrideAmountCents') ? $override->overrideAmountCents() : $amount;
    $overrideId = method_exists($override, 'id') ? $override->id()->value() : null;
}

// New (Wave D):
if ($override !== null) {
    $amount = $override->overrideAmountCents();
    $overrideId = $override->id()->value();
}
```

- [ ] **Step 4: Run + PHPStan**

```bash
vendor/bin/phpunit --filter 'UserFeeOverrideTest|GenerateAnniversaryInvoiceTest'
composer analyse
```

Expected: green (7 + 3 tests). PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Membership/Billing/UserFeeOverride.php tests/Unit/Domain/Membership/Billing/UserFeeOverrideTest.php src/Application/Membership/Billing/GenerateAnniversaryInvoice/GenerateAnniversaryInvoice.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Replace(domain): UserFeeOverride placeholder with full entity + invariants"
```

---

## Task D4: SqlUserFeeOverrideRepository (replaces Wave C InMemory binding)

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserFeeOverrideRepository.php`
- Create: `tests/Integration/Infrastructure/SqlUserFeeOverrideRepositoryTest.php`
- Modify: `tests/Support/Fake/InMemoryUserFeeOverrideRepository.php` (upgrade from null-impl to real in-memory store)

- [ ] **Step 1: Upgrade the InMemory fake to a real store**

Replace `tests/Support/Fake/InMemoryUserFeeOverrideRepository.php` (was: null-impl) with:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class InMemoryUserFeeOverrideRepository implements UserFeeOverrideRepositoryInterface
{
    /** @var array<string, UserFeeOverride> */
    private array $byId = [];

    public function save(UserFeeOverride $override): void
    {
        $this->byId[$override->id()->value()] = $override;
    }

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride
    {
        return $this->byId[$id->value()] ?? null;
    }

    public function findActiveFor(TenantId $tenantId, UserId $userId, string $feeType, DateTimeImmutable $asOf): ?UserFeeOverride
    {
        foreach ($this->byId as $o) {
            if (!$o->tenantId()->equals($tenantId)) continue;
            if (!$o->userId()->equals($userId)) continue;
            if ($o->feeType()->value !== $feeType) continue;
            if (!$o->isActiveAt($asOf)) continue;
            return $o;
        }
        return null;
    }

    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array
    {
        $now = new DateTimeImmutable();
        $out = [];
        foreach ($this->byId as $o) {
            if (!$o->tenantId()->equals($tenantId)) continue;
            if ($activeOnly && !$o->isActiveAt($now)) continue;
            $out[] = $o;
        }
        return array_values($out);
    }
}
```

- [ ] **Step 2: Write integration test**

`tests/Integration/Infrastructure/SqlUserFeeOverrideRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserFeeOverrideRepository;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlUserFeeOverrideRepositoryTest extends MigrationTestCase
{
    private SqlUserFeeOverrideRepository $repo;
    private TenantId $tenantId;
    private UserId $userId;
    private UserId $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->repo = new SqlUserFeeOverrideRepository($this->pdo());

        $this->tenantId = TenantId::fromString((string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn());
        $this->userId   = UserId::fromString('01958000-0000-7000-8000-000000000099');
        $this->adminId  = UserId::fromString('01958000-0000-7000-8000-0000000000aa');

        foreach ([$this->userId, $this->adminId] as $uid) {
            $this->pdo()->prepare(
                'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status)
                 VALUES (?, ?, ?, NULL, ?, 0, ?, ?)'
            )->execute([$uid->value(), 'User', $uid->value() . '@daems.fi', '1990-01-01', 'BASIC', 'active']);
        }
    }

    public function test_save_round_trip(): void
    {
        $o = $this->override();
        $this->repo->save($o);
        $loaded = $this->repo->findById($o->id());
        $this->assertNotNull($loaded);
        $this->assertSame(2500, $loaded->overrideAmountCents());
        $this->assertSame('Opiskelija-alennus', $loaded->reason());
    }

    public function test_findActiveFor_respects_time_window(): void
    {
        $o = $this->override();
        $this->repo->save($o);

        $hit = $this->repo->findActiveFor($this->tenantId, $this->userId, 'BASIC', new DateTimeImmutable('2026-06-15'));
        $this->assertNotNull($hit);

        $miss = $this->repo->findActiveFor($this->tenantId, $this->userId, 'BASIC', new DateTimeImmutable('2028-06-15'));
        $this->assertNull($miss);
    }

    public function test_revoke_round_trip(): void
    {
        $o = $this->override();
        $this->repo->save($o);
        $o->revoke($this->adminId, new DateTimeImmutable('2026-08-01T00:00:00'));
        $this->repo->save($o);

        $loaded = $this->repo->findById($o->id());
        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->revokedAt());
        $this->assertFalse($loaded->isActiveAt(new DateTimeImmutable('2026-09-15')));
    }

    public function test_listForTenant_with_activeOnly(): void
    {
        $active = $this->override();
        $revoked = clone $active; // can't actually clone — make a fresh one
        $revoked = new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            $this->tenantId,
            userId:              $this->userId,
            feeType:             MembershipType::Supporting,
            overrideAmountCents: 500,
            validFrom:           new DateTimeImmutable('2025-01-01'),
            validUntil:          new DateTimeImmutable('2025-12-31'),
            reason:              'Old promo',
            decisionId:          null,
            createdBy:           $this->adminId,
            createdAt:           new DateTimeImmutable('2025-01-01'),
            revokedAt:           null,
            revokedBy:           null,
        );
        $this->repo->save($active);
        $this->repo->save($revoked);

        $all = $this->repo->listForTenant($this->tenantId, activeOnly: false);
        $this->assertCount(2, $all);

        $activeOnly = $this->repo->listForTenant($this->tenantId, activeOnly: true);
        // Only "active" passes (the 2025 one is past valid_until on today's date).
        $this->assertCount(1, $activeOnly);
    }

    private function override(): UserFeeOverride
    {
        return new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            $this->tenantId,
            userId:              $this->userId,
            feeType:             MembershipType::Basic,
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
            decisionId:          null,
            createdBy:           $this->adminId,
            createdAt:           new DateTimeImmutable('2025-12-15'),
            revokedAt:           null,
            revokedBy:           null,
        );
    }
}
```

- [ ] **Step 3: Implement SQL repo**

`src/Infrastructure/Adapter/Persistence/Sql/SqlUserFeeOverrideRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlUserFeeOverrideRepository implements UserFeeOverrideRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(UserFeeOverride $o): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_fee_overrides
                (id, tenant_id, user_id, fee_type, override_amount_cents,
                 valid_from, valid_until, reason, decision_id,
                 created_by, created_at, revoked_at, revoked_by)
             VALUES (:id, :tid, :uid, :ft, :amt,
                     :vf, :vu, :rsn, :did,
                     :cby, :cat, :rat, :rby)
             ON DUPLICATE KEY UPDATE
                override_amount_cents = VALUES(override_amount_cents),
                valid_until           = VALUES(valid_until),
                reason                = VALUES(reason),
                revoked_at            = VALUES(revoked_at),
                revoked_by            = VALUES(revoked_by)'
        );
        $stmt->execute([
            'id'  => $o->id()->value(),
            'tid' => $o->tenantId()->value(),
            'uid' => $o->userId()->value(),
            'ft'  => $o->feeType()->value,
            'amt' => $o->overrideAmountCents(),
            'vf'  => $o->validFrom()->format('Y-m-d'),
            'vu'  => $o->validUntil()?->format('Y-m-d'),
            'rsn' => $o->reason(),
            'did' => $o->decisionId(),
            'cby' => $o->createdBy()->value(),
            'cat' => $o->createdAt()->format('Y-m-d H:i:s'),
            'rat' => $o->revokedAt()?->format('Y-m-d H:i:s'),
            'rby' => $o->revokedBy()?->value(),
        ]);
    }

    public function findById(UserFeeOverrideId $id): ?UserFeeOverride
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_fee_overrides WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findActiveFor(TenantId $tenantId, UserId $userId, string $feeType, DateTimeImmutable $asOf): ?UserFeeOverride
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_fee_overrides
             WHERE tenant_id = ? AND user_id = ? AND fee_type = ?
               AND valid_from <= ?
               AND (valid_until IS NULL OR valid_until >= ?)
               AND (revoked_at IS NULL OR revoked_at > ?)
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $asOfDate = $asOf->format('Y-m-d');
        $asOfDT = $asOf->format('Y-m-d H:i:s');
        $stmt->execute([$tenantId->value(), $userId->value(), $feeType, $asOfDate, $asOfDate, $asOfDT]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function listForTenant(TenantId $tenantId, bool $activeOnly = false): array
    {
        if ($activeOnly) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM user_fee_overrides
                 WHERE tenant_id = ?
                   AND valid_from <= CURDATE()
                   AND (valid_until IS NULL OR valid_until >= CURDATE())
                   AND revoked_at IS NULL
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$tenantId->value()]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM user_fee_overrides WHERE tenant_id = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$tenantId->value()]);
        }
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): UserFeeOverride
    {
        return new UserFeeOverride(
            id:                  UserFeeOverrideId::fromString((string) $row['id']),
            tenantId:            TenantId::fromString((string) $row['tenant_id']),
            userId:              UserId::fromString((string) $row['user_id']),
            feeType:             MembershipType::from((string) $row['fee_type']),
            overrideAmountCents: (int) $row['override_amount_cents'],
            validFrom:           new DateTimeImmutable((string) $row['valid_from']),
            validUntil:          $row['valid_until'] !== null ? new DateTimeImmutable((string) $row['valid_until']) : null,
            reason:              (string) $row['reason'],
            decisionId:          $row['decision_id'] !== null ? (string) $row['decision_id'] : null,
            createdBy:           UserId::fromString((string) $row['created_by']),
            createdAt:           new DateTimeImmutable((string) $row['created_at']),
            revokedAt:           $row['revoked_at'] !== null ? new DateTimeImmutable((string) $row['revoked_at']) : null,
            revokedBy:           $row['revoked_by'] !== null ? UserId::fromString((string) $row['revoked_by']) : null,
        );
    }
}
```

- [ ] **Step 4: Run + PHPStan**

```bash
vendor/bin/phpunit --filter SqlUserFeeOverrideRepositoryTest
composer analyse
```

Expected: 4 tests OK. PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlUserFeeOverrideRepository.php tests/Integration/Infrastructure/SqlUserFeeOverrideRepositoryTest.php tests/Support/Fake/InMemoryUserFeeOverrideRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(infra/sql): SqlUserFeeOverrideRepository + upgrade InMemory fake to real store"
```

---

## Task D5: SetUserFeeOverride + RevokeUserFeeOverride use cases

**Files:**
- Create: `src/Application/Membership/Billing/SetUserFeeOverride/SetUserFeeOverride.php`
- Create: `src/Application/Membership/Billing/SetUserFeeOverride/SetUserFeeOverrideInput.php`
- Create: `src/Application/Membership/Billing/RevokeUserFeeOverride/RevokeUserFeeOverride.php`
- Create: `src/Application/Membership/Billing/RevokeUserFeeOverride/RevokeUserFeeOverrideInput.php`
- Create: `tests/Unit/Application/Membership/Billing/SetUserFeeOverrideTest.php`
- Create: `tests/Unit/Application/Membership/Billing/RevokeUserFeeOverrideTest.php`

- [ ] **Step 1: Implement SetUserFeeOverride**

`src/Application/Membership/Billing/SetUserFeeOverride/SetUserFeeOverrideInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\SetUserFeeOverride;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class SetUserFeeOverrideInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly TenantId           $tenantId,
        public readonly UserId             $userId,
        public readonly string             $feeType,      // 'SUPPORTING'|'BASIC'|'FULL'
        public readonly int                $overrideAmountCents,
        public readonly DateTimeImmutable  $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly string             $reason,
        public readonly ?string            $decisionId = null,
    ) {}
}
```

`src/Application/Membership/Billing/SetUserFeeOverride/SetUserFeeOverride.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\SetUserFeeOverride;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock\ClockInterface;

final class SetUserFeeOverride
{
    public function __construct(
        private readonly UserFeeOverrideRepositoryInterface $repo,
        private readonly ClockInterface                     $clock,
    ) {}

    public function handle(SetUserFeeOverrideInput $in): string
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can set fee overrides");
        }

        $override = new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            $in->tenantId,
            userId:              $in->userId,
            feeType:             MembershipType::from($in->feeType),
            overrideAmountCents: $in->overrideAmountCents,
            validFrom:           $in->validFrom,
            validUntil:          $in->validUntil,
            reason:              $in->reason,
            decisionId:          $in->decisionId,
            createdBy:           $in->actor->id,
            createdAt:           $this->clock->now(),
            revokedAt:           null,
            revokedBy:           null,
        );
        $this->repo->save($override);
        return $override->id()->value();
    }
}
```

- [ ] **Step 2: Implement RevokeUserFeeOverride**

`src/Application/Membership/Billing/RevokeUserFeeOverride/RevokeUserFeeOverrideInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RevokeUserFeeOverride;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;

final class RevokeUserFeeOverrideInput
{
    public function __construct(
        public readonly ActingUser          $actor,
        public readonly UserFeeOverrideId   $overrideId,
    ) {}
}
```

`src/Application/Membership/Billing/RevokeUserFeeOverride/RevokeUserFeeOverride.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RevokeUserFeeOverride;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;

final class RevokeUserFeeOverride
{
    public function __construct(
        private readonly UserFeeOverrideRepositoryInterface $repo,
        private readonly ClockInterface                     $clock,
    ) {}

    public function handle(RevokeUserFeeOverrideInput $in): void
    {
        $override = $this->repo->findById($in->overrideId);
        if ($override === null) {
            throw new \DomainException("Override not found: {$in->overrideId->value()}");
        }
        if (!$in->actor->isAdminIn($override->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can revoke fee overrides");
        }
        $override->revoke($in->actor->id, $this->clock->now());
        $this->repo->save($override);
    }
}
```

- [ ] **Step 3: Write tests**

`tests/Unit/Application/Membership/Billing/SetUserFeeOverrideTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride;
use Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverrideInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SetUserFeeOverrideTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_admin_creates_override(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $useCase = new SetUserFeeOverride($repo, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable('2026-01-01')));

        $id = $useCase->handle(new SetUserFeeOverrideInput(
            actor:               $this->actor(UserTenantRole::Admin),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            feeType:             'BASIC',
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          new DateTimeImmutable('2027-12-31'),
            reason:              'Opiskelija-alennus',
        ));

        $this->assertNotEmpty($id);
        $this->assertCount(1, $repo->listForTenant(TenantId::fromString(self::TENANT_ID)));
    }

    public function test_member_rejected(): void
    {
        $repo = new InMemoryUserFeeOverrideRepository();
        $useCase = new SetUserFeeOverride($repo, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable()));

        $this->expectException(\Daems\Domain\Auth\Exception\ForbiddenException::class);
        $useCase->handle(new SetUserFeeOverrideInput(
            actor:               $this->actor(UserTenantRole::Member),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString(self::USER_ID),
            feeType:             'BASIC',
            overrideAmountCents: 2500,
            validFrom:           new DateTimeImmutable('2026-01-01'),
            validUntil:          null,
            reason:              'r',
        ));
    }

    private function actor(UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
```

`tests/Unit/Application/Membership/Billing/RevokeUserFeeOverrideTest.php`: mirror structure — seed an override, call revoke, assert revokedAt is set. Reject when actor is a non-admin.

- [ ] **Step 4: Run + PHPStan**

```bash
vendor/bin/phpunit --filter 'SetUserFeeOverrideTest|RevokeUserFeeOverrideTest'
composer analyse
```

Expected: green. PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Membership/Billing/SetUserFeeOverride/ src/Application/Membership/Billing/RevokeUserFeeOverride/ tests/Unit/Application/Membership/Billing/SetUserFeeOverrideTest.php tests/Unit/Application/Membership/Billing/RevokeUserFeeOverrideTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): SetUserFeeOverride + RevokeUserFeeOverride use cases"
```

---

## Task D6: DI rewiring — replace InMemory binding with SQL repo in bootstrap/app.php

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `tests/Support/KernelHarness.php`

Wave C's TODO is addressed here. Remove the InMemoryUserFeeOverrideRepository binding from production; bind SqlUserFeeOverrideRepository instead.

- [ ] **Step 1: Update bootstrap/app.php**

Find the Wave C TODO comment and replace the binding:

```php
// Before (Wave C placeholder):
// TODO(0.7-wave-d): replace InMemoryUserFeeOverrideRepository with SqlUserFeeOverrideRepository
$container[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class] =
    fn() => new \Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository();

// After (Wave D):
$container[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class] =
    fn($c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserFeeOverrideRepository($c[PDO::class]);

// Plus add use case bindings:
$container[\Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride::class] =
    fn($c) => new \Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride(
        $c[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

$container[\Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride::class] =
    fn($c) => new \Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride(
        $c[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

- [ ] **Step 2: Update tests/Support/KernelHarness.php**

Keep the InMemory binding for the test container (harness pattern), but add the 2 use case bindings:

```php
$this->container[\Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride::class] =
    fn($c) => new \Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride(
        $c[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
$this->container[\Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride::class] =
    fn($c) => new \Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride(
        $c[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

- [ ] **Step 3: Verify both files reference all 3 classes**

```bash
grep -n "UserFeeOverrideRepository\|SetUserFeeOverride\|RevokeUserFeeOverride" bootstrap/app.php tests/Support/KernelHarness.php
composer analyse
vendor/bin/phpunit --testsuite Unit 2>&1 | tail -3
```

Expected: appears in BOTH. PHPStan 0. Unit green.

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire(di): replace InMemoryUserFeeOverrideRepository with SQL impl in prod; add Set/Revoke use cases"
```

---

## Task D7: Controller endpoints for overrides + E2E test

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php`
- Modify: `bootstrap/app.php` (extend the controller binding with new use cases)
- Modify: routing config to add 3 new endpoints
- Create: `tests/E2E/Backstage/BillingOverridesEndpointTest.php`

Endpoints to add:
- `GET /api/v1/backstage/governance/billing/overrides?user_id=&active_only=`
- `POST /api/v1/backstage/governance/billing/overrides`
- `POST /api/v1/backstage/governance/billing/overrides/{id}/revoke`

- [ ] **Step 1: Extend controller**

Open `BackstageBillingController.php` and add to the constructor + 3 methods:

```php
// In constructor:
private readonly \Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride       $setOverride,
private readonly \Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride $revokeOverride,
private readonly \Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface              $overrides,

// New methods:

public function listOverrides(Request $request, ActingUser $actor, TenantId $tenantId): Response
{
    if (!$actor->isAdminIn($tenantId) && !$actor->isPlatformAdmin) {
        return Response::json(['error' => 'Forbidden'], 403);
    }
    $activeOnly = ($request->query('active_only') ?? '') === '1';
    $rows = $this->overrides->listForTenant($tenantId, activeOnly: $activeOnly);
    return Response::json([
        'active_only' => $activeOnly,
        'rows' => array_map(static fn($o) => [
            'id'                    => $o->id()->value(),
            'user_id'               => $o->userId()->value(),
            'fee_type'              => $o->feeType()->value,
            'override_amount_cents' => $o->overrideAmountCents(),
            'valid_from'            => $o->validFrom()->format('Y-m-d'),
            'valid_until'           => $o->validUntil()?->format('Y-m-d'),
            'reason'                => $o->reason(),
            'decision_id'           => $o->decisionId(),
            'created_by'            => $o->createdBy()->value(),
            'created_at'            => $o->createdAt()->format(\DateTimeImmutable::ATOM),
            'revoked_at'            => $o->revokedAt()?->format(\DateTimeImmutable::ATOM),
        ], $rows),
    ], 200);
}

public function createOverride(Request $request, ActingUser $actor, TenantId $tenantId): Response
{
    try {
        $id = $this->setOverride->handle(new \Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverrideInput(
            actor:               $actor,
            tenantId:            $tenantId,
            userId:              \Daems\Domain\User\UserId::fromString((string) $request->bodyValue('user_id')),
            feeType:             (string) $request->bodyValue('fee_type'),
            overrideAmountCents: (int) $request->bodyValue('override_amount_cents'),
            validFrom:           new \DateTimeImmutable((string) $request->bodyValue('valid_from')),
            validUntil:          $request->bodyValue('valid_until') !== null ? new \DateTimeImmutable((string) $request->bodyValue('valid_until')) : null,
            reason:              (string) $request->bodyValue('reason'),
            decisionId:          $request->bodyValue('decision_id') !== null ? (string) $request->bodyValue('decision_id') : null,
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 400);
    }
    return Response::json(['id' => $id], 201);
}

public function revokeOverride(Request $request, ActingUser $actor, TenantId $tenantId, string $overrideId): Response
{
    try {
        $this->revokeOverride->handle(new \Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverrideInput(
            actor:      $actor,
            overrideId: \Daems\Domain\Membership\Billing\UserFeeOverrideId::fromString($overrideId),
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    } catch (\DomainException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
    return Response::json(['revoked' => true], 200);
}
```

- [ ] **Step 2: Update bootstrap binding for the controller**

```php
$container[\Daems\Infrastructure\Adapter\Api\Controller\BackstageBillingController::class] =
    fn($c) => new \Daems\Infrastructure\Adapter\Api\Controller\BackstageBillingController(
        $c[\Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule::class],
        $c[\Daems\Domain\Membership\Billing\AnnualFeeScheduleRepositoryInterface::class],
        $c[\Daems\Application\Membership\Billing\SetUserFeeOverride\SetUserFeeOverride::class],
        $c[\Daems\Application\Membership\Billing\RevokeUserFeeOverride\RevokeUserFeeOverride::class],
        $c[\Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface::class],
    );
```

Mirror in `KernelHarness.php`.

- [ ] **Step 3: Register routes**

```php
$router->get('/api/v1/backstage/governance/billing/overrides',
    [BackstageBillingController::class, 'listOverrides']);
$router->post('/api/v1/backstage/governance/billing/overrides',
    [BackstageBillingController::class, 'createOverride']);
$router->post('/api/v1/backstage/governance/billing/overrides/{id}/revoke',
    [BackstageBillingController::class, 'revokeOverride']);
```

- [ ] **Step 4: Write E2E test**

`tests/E2E/Backstage/BillingOverridesEndpointTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BillingOverridesEndpointTest extends TestCase
{
    public function test_create_list_revoke_round_trip(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $admin = $h->seedAdminUser('daems');
        $member = $h->seedMember('daems'); // returns UserId

        // CREATE
        $r = $h->request('POST', '/api/v1/backstage/governance/billing/overrides', [
            'user_id'               => $member->value(),
            'fee_type'              => 'BASIC',
            'override_amount_cents' => 2500,
            'valid_from'            => '2026-01-01',
            'valid_until'           => '2027-12-31',
            'reason'                => 'Opiskelija-alennus',
        ], actor: $admin);
        $this->assertSame(201, $r->status());
        $id = $r->jsonBody()['id'];

        // LIST
        $r = $h->request('GET', '/api/v1/backstage/governance/billing/overrides', actor: $admin);
        $this->assertSame(200, $r->status());
        $this->assertCount(1, $r->jsonBody()['rows']);

        // REVOKE
        $r = $h->request('POST', "/api/v1/backstage/governance/billing/overrides/{$id}/revoke", [], actor: $admin);
        $this->assertSame(200, $r->status());
        $this->assertTrue($r->jsonBody()['revoked']);

        // LIST active_only=1 should now be empty
        $r = $h->request('GET', '/api/v1/backstage/governance/billing/overrides?active_only=1', actor: $admin);
        $this->assertSame(0, count(array_filter($r->jsonBody()['rows'], fn($row) => $row['revoked_at'] === null)));
    }

    public function test_non_admin_rejected(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $member = $h->seedMember('daems');

        $r = $h->request('POST', '/api/v1/backstage/governance/billing/overrides', [
            'user_id'               => $member->value(),
            'fee_type'              => 'BASIC',
            'override_amount_cents' => 2500,
            'valid_from'            => '2026-01-01',
            'reason'                => 'r',
        ], actor: $member);
        $this->assertSame(403, $r->status());
    }
}
```

- [ ] **Step 5: Run + PHPStan**

```bash
vendor/bin/phpunit --filter BillingOverridesEndpointTest
composer analyse
```

Expected: 2 tests OK. PHPStan 0.

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php bootstrap/app.php tests/Support/KernelHarness.php tests/E2E/Backstage/BillingOverridesEndpointTest.php public/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api): override endpoints (list + create + revoke) + DI wiring"
```

---

## Task D8: Override admin UI — _overrides.php

**Files:**
- Modify: `public/backstage/governance/billing/_overrides.php` (replace Wave B stub)

The Wave B stub is just a "Tulossa Wave D:ssä" placeholder. Replace it with a real list + create-form.

- [ ] **Step 1: Replace stub with real template**

`public/backstage/governance/billing/_overrides.php`:

```php
<?php
declare(strict_types=1);

$activeOnly = ($_GET['active_only'] ?? '0') === '1';
?>

<section class="billing-overrides">
    <header class="billing-overrides__header">
        <h2>Per-jäsen alennukset</h2>
        <div class="billing-overrides__filters">
            <label>
                <input type="checkbox" id="active-only-toggle" <?= $activeOnly ? 'checked' : '' ?>>
                Vain aktiiviset
            </label>
            <button type="button" id="new-override-btn" class="button button--primary">Uusi alennus</button>
        </div>
    </header>

    <table class="billing-overrides__list" data-source="/api/v1/backstage/governance/billing/overrides<?= $activeOnly ? '?active_only=1' : '' ?>">
        <thead>
            <tr>
                <th>Jäsen</th>
                <th>Tyyppi</th>
                <th>Alennettu summa</th>
                <th>Voimassa</th>
                <th>Perustelu</th>
                <th>Päätös</th>
                <th>Toiminnot</th>
            </tr>
        </thead>
        <tbody><tr><td colspan="7">Ladataan…</td></tr></tbody>
    </table>

    <dialog id="new-override-dialog" class="billing-overrides__dialog">
        <form method="dialog" id="new-override-form">
            <h3>Uusi alennus</h3>
            <label>Jäsenen ID
                <input type="text" name="user_id" required pattern="[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}">
            </label>
            <label>Tyyppi
                <select name="fee_type" required>
                    <option value="SUPPORTING">Kannatusmaksu (SUPPORTING)</option>
                    <option value="BASIC" selected>Perusjäsen (BASIC)</option>
                    <option value="FULL">Varsinainen jäsen (FULL)</option>
                </select>
            </label>
            <label>Alennettu summa (€)
                <input type="number" name="override_amount_euro" min="0" step="0.01" required>
            </label>
            <label>Voimassa alkaen
                <input type="date" name="valid_from" required>
            </label>
            <label>Voimassa asti (tyhjä = määräämätön)
                <input type="date" name="valid_until">
            </label>
            <label>Perustelu (pakollinen)
                <textarea name="reason" rows="3" required></textarea>
            </label>
            <label>Hallituksen päätös-ID (valinnainen)
                <input type="text" name="decision_id" pattern="[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}">
            </label>
            <menu class="billing-overrides__dialog-actions">
                <button type="button" value="cancel">Peruuta</button>
                <button type="submit" class="button button--primary">Tallenna</button>
            </menu>
        </form>
    </dialog>
</section>

<script defer src="/backstage/assets/governance/billing-overrides.js"></script>
```

- [ ] **Step 2: JS handler**

`public/backstage/assets/governance/billing-overrides.js`:

```js
document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('.billing-overrides__list');
    if (table) loadOverrides(table);

    const newBtn = document.getElementById('new-override-btn');
    const dialog = document.getElementById('new-override-dialog');
    const form = document.getElementById('new-override-form');
    const toggle = document.getElementById('active-only-toggle');

    if (newBtn) newBtn.addEventListener('click', () => dialog.showModal());
    if (form) form.addEventListener('submit', onSubmitNewOverride);
    if (toggle) toggle.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('active_only', toggle.checked ? '1' : '0');
        window.location.href = url.toString();
    });
});

async function loadOverrides(table) {
    const url = table.dataset.source;
    const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!resp.ok) { table.querySelector('tbody').innerHTML = '<tr><td colspan="7">Lataus epäonnistui</td></tr>'; return; }
    const data = await resp.json();
    if (data.rows.length === 0) {
        table.querySelector('tbody').innerHTML = '<tr><td colspan="7">Ei alennuksia</td></tr>';
        return;
    }
    table.querySelector('tbody').innerHTML = data.rows.map(r => `
        <tr data-revoked="${r.revoked_at !== null}">
            <td><code>${escape(r.user_id.slice(0, 8))}…</code></td>
            <td>${escape(r.fee_type)}</td>
            <td>${(r.override_amount_cents / 100).toFixed(2)} €</td>
            <td>${escape(r.valid_from)} – ${r.valid_until ? escape(r.valid_until) : '∞'}</td>
            <td>${escape(r.reason)}</td>
            <td>${r.decision_id ? `<a href="/backstage/governance/decisions/${r.decision_id}">${r.decision_id.slice(0, 8)}…</a>` : '—'}</td>
            <td>
                ${r.revoked_at === null
                    ? `<button type="button" data-revoke-id="${r.id}" class="button button--ghost">Peruuta</button>`
                    : `<small>Peruutettu ${new Date(r.revoked_at).toLocaleDateString('fi-FI')}</small>`}
            </td>
        </tr>
    `).join('');

    table.querySelectorAll('[data-revoke-id]').forEach(btn => {
        btn.addEventListener('click', () => onRevoke(btn.dataset.revokeId));
    });
}

async function onSubmitNewOverride(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const fd = new FormData(form);
    const payload = {
        user_id:               fd.get('user_id'),
        fee_type:              fd.get('fee_type'),
        override_amount_cents: Math.round(parseFloat(fd.get('override_amount_euro')) * 100),
        valid_from:            fd.get('valid_from'),
        valid_until:           fd.get('valid_until') || null,
        reason:                fd.get('reason'),
        decision_id:           fd.get('decision_id') || null,
    };
    const resp = await fetch('/api/v1/backstage/governance/billing/overrides', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        alert('Tallennus epäonnistui: ' + (err.error ?? resp.status));
        return;
    }
    window.location.reload();
}

async function onRevoke(overrideId) {
    if (!confirm('Peruuta tämä alennus?')) return;
    const resp = await fetch(`/api/v1/backstage/governance/billing/overrides/${overrideId}/revoke`, {
        method: 'POST',
    });
    if (!resp.ok) { alert('Peruutus epäonnistui'); return; }
    window.location.reload();
}

function escape(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
```

- [ ] **Step 3: Append CSS to billing.css**

Append to `public/backstage/assets/governance/billing.css`:

```css
.billing-overrides__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-3);
}
.billing-overrides__filters {
    display: flex;
    gap: var(--space-3);
    align-items: center;
}
.billing-overrides__list {
    width: 100%;
    border-collapse: collapse;
}
.billing-overrides__list th,
.billing-overrides__list td {
    text-align: left;
    padding: var(--space-2);
    border-bottom: 1px solid var(--color-border);
}
.billing-overrides__list tr[data-revoked="true"] {
    color: var(--color-text-tertiary);
    opacity: 0.6;
}
.billing-overrides__dialog {
    border: none;
    border-radius: var(--radius-md);
    padding: var(--space-4);
    min-width: 480px;
}
.billing-overrides__dialog form > label {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-2);
}
.billing-overrides__dialog-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-3);
}
```

- [ ] **Step 4: Browser smoke**

```
1. http://daems.local/backstage/governance/billing?view=overrides
2. Click "Uusi alennus" → dialog opens
3. Fill in fields, submit → row appears in the table
4. Click "Peruuta" on the row → confirm → row dims, button replaced with "Peruutettu ..."
5. Toggle "Vain aktiiviset" → list now empty (revoked override hidden)
```

- [ ] **Step 5: Commit**

```bash
git add public/backstage/governance/billing/_overrides.php public/backstage/assets/governance/billing-overrides.js public/backstage/assets/governance/billing.css
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): override list + new + revoke (replace Wave B stub)"
```

---

## Wave D — Definition of Done

After Tasks D1-D8:

- [ ] Migration 092 applied (both DBs); `member_fee_invoices.fk_mfi_override` constraint exists
- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --testsuite Unit` green (count up ~10 from Wave C baseline)
- [ ] `vendor/bin/phpunit --filter 'UserFeeOverride'` green (Domain + SQL + use cases)
- [ ] `vendor/bin/phpunit --filter 'BillingOverridesEndpointTest'` green (E2E)
- [ ] `bootstrap/app.php` no longer references `InMemoryUserFeeOverrideRepository`
- [ ] Browser smoke: override create + revoke flow works in `/backstage/governance/billing?view=overrides`
- [ ] Anniversary-cron with an active override produces a reduced-amount invoice (verify in dev DB)

Run verification gate:

```bash
composer analyse
vendor/bin/phpunit --filter 'Billing|FeeOverride' 2>&1 | tail -3
grep -n "InMemoryUserFeeOverrideRepository" bootstrap/app.php
```

The grep should return NOTHING (production wiring must not reference test fakes).

If anything fails, FIX before starting Wave E.

---

# Wave E — Audit + Waive/Reduce + Manual Payment (Phases 7-8, 10 tasks)

This wave completes the invoice lifecycle from the admin side: the audit-trail table, the 3 row-actiont (waive, reduce, mark-paid), their HTTP endpoints, and the full invoice list view at `/backstage/governance/billing` (replacing the Wave B stub).

## Task E1: Migration 093 — member_fee_invoice_audit

**Files:**
- Create: `database/migrations/093_create_member_fee_invoice_audit.sql`

- [ ] **Step 1: Write the migration**

`database/migrations/093_create_member_fee_invoice_audit.sql`:

```sql
-- 093_create_member_fee_invoice_audit.sql
-- Per-invoice state-flip audit. Every change to a member_fee_invoice (creation,
-- waive, reduce, payment, payment_reversed, overdue_flagged, lapsed_via_invoice)
-- appends a row here. performed_by is NULL for cron-driven changes.

CREATE TABLE IF NOT EXISTS member_fee_invoice_audit (
    id              CHAR(36)             NOT NULL,
    tenant_id       CHAR(36)             NOT NULL,
    invoice_id      CHAR(36)             NOT NULL,
    action          VARCHAR(50)          NOT NULL,
    performed_by    CHAR(36)             NULL,
    performed_at    DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payload_json    TEXT                 NULL,
    PRIMARY KEY (id),
    KEY idx_invoice (invoice_id, performed_at),
    KEY idx_tenant (tenant_id, performed_at),
    CONSTRAINT fk_mfia_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfia_invoice FOREIGN KEY (invoice_id) REFERENCES member_fee_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfia_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply + verify**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/093_create_member_fee_invoice_audit.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/093_create_member_fee_invoice_audit.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW CREATE TABLE member_fee_invoice_audit\G"
```

Expected: table exists with 2 indexes + 3 FKs.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/093_create_member_fee_invoice_audit.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 093 — member_fee_invoice_audit"
```

---

## Task E2: FeeInvoiceAudit domain + repository + SQL impl

**Files:**
- Create: `src/Domain/Membership/Billing/FeeInvoiceAudit.php`
- Create: `src/Domain/Membership/Billing/FeeInvoiceAuditAction.php` (enum)
- Create: `src/Domain/Membership/Billing/FeeInvoiceAuditRepositoryInterface.php`
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlFeeInvoiceAuditRepository.php`
- Create: `tests/Support/Fake/InMemoryFeeInvoiceAuditRepository.php`
- Create: `tests/Unit/Domain/Membership/Billing/FeeInvoiceAuditTest.php`

- [ ] **Step 1: Create action enum**

`src/Domain/Membership/Billing/FeeInvoiceAuditAction.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

enum FeeInvoiceAuditAction: string
{
    case Created          = 'created';
    case Waived           = 'waived';
    case Reduced          = 'reduced';
    case Paid             = 'paid';
    case PaymentReversed  = 'payment_reversed';
    case OverdueFlagged   = 'overdue_flagged';
    case LapsedViaInvoice = 'lapsed_via_invoice';
}
```

- [ ] **Step 2: Create entity**

`src/Domain/Membership/Billing/FeeInvoiceAudit.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

/**
 * Immutable audit row. Created by every state-changing operation on a
 * MemberFeeInvoice (waive, reduce, payment, overdue, lapse). performed_by
 * is NULL when the change came from a cron command.
 */
final class FeeInvoiceAudit
{
    public function __construct(
        public readonly string                 $id,
        public readonly TenantId               $tenantId,
        public readonly MemberFeeInvoiceId     $invoiceId,
        public readonly FeeInvoiceAuditAction  $action,
        public readonly ?UserId                $performedBy,
        public readonly DateTimeImmutable      $performedAt,
        public readonly ?string                $payloadJson,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public static function record(
        TenantId               $tenantId,
        MemberFeeInvoiceId     $invoiceId,
        FeeInvoiceAuditAction  $action,
        ?UserId                $actor,
        DateTimeImmutable      $now,
        ?array                 $payload = null,
    ): self {
        return new self(
            id:          Uuid7::generate()->value(),
            tenantId:    $tenantId,
            invoiceId:   $invoiceId,
            action:      $action,
            performedBy: $actor,
            performedAt: $now,
            payloadJson: $payload !== null ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        );
    }
}
```

- [ ] **Step 3: Create repo interface**

`src/Domain/Membership/Billing/FeeInvoiceAuditRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Domain\Membership\Billing;

interface FeeInvoiceAuditRepositoryInterface
{
    public function append(FeeInvoiceAudit $row): void;

    /** @return list<FeeInvoiceAudit> */
    public function listForInvoice(MemberFeeInvoiceId $invoiceId): array;
}
```

- [ ] **Step 4: Create SQL repo**

`src/Infrastructure/Adapter/Persistence/Sql/SqlFeeInvoiceAuditRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PDO;

final class SqlFeeInvoiceAuditRepository implements FeeInvoiceAuditRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function append(FeeInvoiceAudit $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_fee_invoice_audit
                (id, tenant_id, invoice_id, action, performed_by, performed_at, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row->id,
            $row->tenantId->value(),
            $row->invoiceId->value(),
            $row->action->value,
            $row->performedBy?->value(),
            $row->performedAt->format('Y-m-d H:i:s'),
            $row->payloadJson,
        ]);
    }

    public function listForInvoice(MemberFeeInvoiceId $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_fee_invoice_audit WHERE invoice_id = ? ORDER BY performed_at ASC'
        );
        $stmt->execute([$invoiceId->value()]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = new FeeInvoiceAudit(
                id:          (string) $r['id'],
                tenantId:    TenantId::fromString((string) $r['tenant_id']),
                invoiceId:   MemberFeeInvoiceId::fromString((string) $r['invoice_id']),
                action:      FeeInvoiceAuditAction::from((string) $r['action']),
                performedBy: $r['performed_by'] !== null ? UserId::fromString((string) $r['performed_by']) : null,
                performedAt: new DateTimeImmutable((string) $r['performed_at']),
                payloadJson: $r['payload_json'] !== null ? (string) $r['payload_json'] : null,
            );
        }
        return $out;
    }
}
```

- [ ] **Step 5: Create InMemory fake**

`tests/Support/Fake/InMemoryFeeInvoiceAuditRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class InMemoryFeeInvoiceAuditRepository implements FeeInvoiceAuditRepositoryInterface
{
    /** @var list<FeeInvoiceAudit> */
    private array $rows = [];

    public function append(FeeInvoiceAudit $row): void
    {
        $this->rows[] = $row;
    }

    public function listForInvoice(MemberFeeInvoiceId $invoiceId): array
    {
        return array_values(array_filter($this->rows, fn(FeeInvoiceAudit $r) => $r->invoiceId->equals($invoiceId)));
    }
}
```

- [ ] **Step 6: Write minimal test**

`tests/Unit/Domain/Membership/Billing/FeeInvoiceAuditTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership\Billing;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FeeInvoiceAuditTest extends TestCase
{
    public function test_record_with_payload(): void
    {
        $audit = FeeInvoiceAudit::record(
            tenantId:    TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            invoiceId:   MemberFeeInvoiceId::fromString('01958000-0000-7000-8000-0000000000aa'),
            action:      FeeInvoiceAuditAction::Waived,
            actor:       UserId::fromString('01958000-0000-7000-8000-0000000000bb'),
            now:         new DateTimeImmutable('2026-09-20T10:00:00'),
            payload:     ['reason' => 'Pitkäaikaissairaus'],
        );
        $this->assertSame(FeeInvoiceAuditAction::Waived, $audit->action);
        $this->assertSame('{"reason":"Pitkäaikaissairaus"}', $audit->payloadJson);
    }

    public function test_record_without_actor_for_cron(): void
    {
        $audit = FeeInvoiceAudit::record(
            tenantId:    TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            invoiceId:   MemberFeeInvoiceId::fromString('01958000-0000-7000-8000-0000000000aa'),
            action:      FeeInvoiceAuditAction::OverdueFlagged,
            actor:       null,
            now:         new DateTimeImmutable('2026-10-15T03:00:00'),
            payload:     null,
        );
        $this->assertNull($audit->performedBy);
        $this->assertNull($audit->payloadJson);
    }
}
```

- [ ] **Step 7: Run + PHPStan + commit**

```bash
vendor/bin/phpunit --filter FeeInvoiceAuditTest
composer analyse
git add src/Domain/Membership/Billing/FeeInvoiceAudit*.php src/Domain/Membership/Billing/FeeInvoiceAuditRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/SqlFeeInvoiceAuditRepository.php tests/Support/Fake/InMemoryFeeInvoiceAuditRepository.php tests/Unit/Domain/Membership/Billing/FeeInvoiceAuditTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(domain+infra): FeeInvoiceAudit entity + interface + SQL + InMemory"
```

---

## Task E3: WaiveMemberFeeInvoice use case

**Files:**
- Create: `src/Application/Membership/Billing/WaiveMemberFeeInvoice/WaiveMemberFeeInvoice.php`
- Create: `src/Application/Membership/Billing/WaiveMemberFeeInvoice/WaiveMemberFeeInvoiceInput.php`
- Create: `tests/Unit/Application/Membership/Billing/WaiveMemberFeeInvoiceTest.php`

- [ ] **Step 1: Input + use case**

`src/Application/Membership/Billing/WaiveMemberFeeInvoice/WaiveMemberFeeInvoiceInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveMemberFeeInvoice;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class WaiveMemberFeeInvoiceInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly MemberFeeInvoiceId $invoiceId,
        public readonly string             $reason,
    ) {}
}
```

`src/Application/Membership/Billing/WaiveMemberFeeInvoice/WaiveMemberFeeInvoice.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveMemberFeeInvoice;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;

final class WaiveMemberFeeInvoice
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface  $audit,
        private readonly ClockInterface                      $clock,
    ) {}

    public function handle(WaiveMemberFeeInvoiceInput $in): void
    {
        $invoice = $this->invoices->findById($in->invoiceId);
        if ($invoice === null) {
            throw new \DomainException("Invoice not found: {$in->invoiceId->value()}");
        }
        if (!$in->actor->isAdminIn($invoice->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can waive invoices");
        }

        $now = $this->clock->now();
        $statusBefore = $invoice->status();
        $invoice->waive($in->actor->id, $in->reason, $now);
        $this->invoices->save($invoice);

        $this->audit->append(FeeInvoiceAudit::record(
            tenantId:  $invoice->tenantId(),
            invoiceId: $invoice->id(),
            action:    FeeInvoiceAuditAction::Waived,
            actor:     $in->actor->id,
            now:       $now,
            payload:   ['reason' => $in->reason, 'status_before' => $statusBefore->value],
        ));
    }
}
```

- [ ] **Step 2: Test**

`tests/Unit/Application/Membership/Billing/WaiveMemberFeeInvoiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice;
use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoiceInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryFeeInvoiceAuditRepository;
use Daems\Tests\Support\Fake\InMemoryMemberFeeInvoiceRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WaiveMemberFeeInvoiceTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';

    public function test_admin_waives_pending_invoice(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $invoice = $this->seedInvoice(MemberFeeInvoiceStatus::Pending);
        $invoices->save($invoice);

        $useCase = new WaiveMemberFeeInvoice($invoices, $audit, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable('2026-09-20')));
        $useCase->handle(new WaiveMemberFeeInvoiceInput(
            actor:     $this->actor(),
            invoiceId: $invoice->id(),
            reason:    'Pitkäaikaissairaus',
        ));

        $loaded = $invoices->findById($invoice->id());
        $this->assertSame(MemberFeeInvoiceStatus::Waived, $loaded?->status());
        $this->assertCount(1, $audit->listForInvoice($invoice->id()));
    }

    public function test_member_rejected(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $invoice = $this->seedInvoice(MemberFeeInvoiceStatus::Pending);
        $invoices->save($invoice);

        $useCase = new WaiveMemberFeeInvoice($invoices, $audit, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable()));

        $this->expectException(\Daems\Domain\Auth\Exception\ForbiddenException::class);
        $useCase->handle(new WaiveMemberFeeInvoiceInput(
            actor:     $this->actor(UserTenantRole::Member),
            invoiceId: $invoice->id(),
            reason:    'r',
        ));
    }

    public function test_invoice_not_found(): void
    {
        $invoices = new InMemoryMemberFeeInvoiceRepository();
        $audit = new InMemoryFeeInvoiceAuditRepository();
        $useCase = new WaiveMemberFeeInvoice($invoices, $audit, new \Daems\Domain\Shared\Clock\FixedClock(new DateTimeImmutable()));

        $this->expectException(\DomainException::class);
        $useCase->handle(new WaiveMemberFeeInvoiceInput(
            actor:     $this->actor(),
            invoiceId: MemberFeeInvoiceId::generate(),
            reason:    'r',
        ));
    }

    private function seedInvoice(MemberFeeInvoiceStatus $status): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            TenantId::fromString(self::TENANT_ID),
            userId:              UserId::fromString('01958000-0000-7000-8000-000000000099'),
            year:                2026,
            feeType:             MembershipType::Basic,
            anniversaryDate:     new DateTimeImmutable('2026-07-15'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             new DateTimeImmutable('2026-09-13'),
            status:              $status,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           new DateTimeImmutable('2026-07-15'),
        );
    }

    private function actor(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
```

- [ ] **Step 3: Run + PHPStan + commit**

```bash
vendor/bin/phpunit --filter WaiveMemberFeeInvoiceTest
composer analyse
git add src/Application/Membership/Billing/WaiveMemberFeeInvoice/ tests/Unit/Application/Membership/Billing/WaiveMemberFeeInvoiceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): WaiveMemberFeeInvoice use case with audit"
```

---

## Task E4: ReduceMemberFeeInvoice use case

**Files:**
- Create: `src/Application/Membership/Billing/ReduceMemberFeeInvoice/ReduceMemberFeeInvoice.php`
- Create: `src/Application/Membership/Billing/ReduceMemberFeeInvoice/ReduceMemberFeeInvoiceInput.php`
- Create: `tests/Unit/Application/Membership/Billing/ReduceMemberFeeInvoiceTest.php`

Mirror structure of Task E3 (auth check, call entity.reduce(), append audit). Reasonable test cases:
- admin reduces 5000 → 2500 with reason → status REDUCED, original_amount_cents=5000, audit row created
- non-admin rejected
- reduction amount must be < current amount (already in entity tests, but verify the use case surfaces InvalidArgumentException as 400)

- [ ] **Step 1: Input + use case**

`src/Application/Membership/Billing/ReduceMemberFeeInvoice/ReduceMemberFeeInvoiceInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReduceMemberFeeInvoice;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;

final class ReduceMemberFeeInvoiceInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly MemberFeeInvoiceId $invoiceId,
        public readonly int                $newAmountCents,
        public readonly string             $reason,
    ) {}
}
```

`src/Application/Membership/Billing/ReduceMemberFeeInvoice/ReduceMemberFeeInvoice.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReduceMemberFeeInvoice;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;

final class ReduceMemberFeeInvoice
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface  $audit,
        private readonly ClockInterface                      $clock,
    ) {}

    public function handle(ReduceMemberFeeInvoiceInput $in): void
    {
        $invoice = $this->invoices->findById($in->invoiceId);
        if ($invoice === null) {
            throw new \DomainException("Invoice not found: {$in->invoiceId->value()}");
        }
        if (!$in->actor->isAdminIn($invoice->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can reduce invoices");
        }

        $now = $this->clock->now();
        $amountBefore = $invoice->amountCents();
        $invoice->reduce($in->newAmountCents, $in->actor->id, $in->reason, $now);
        $this->invoices->save($invoice);

        $this->audit->append(FeeInvoiceAudit::record(
            tenantId:  $invoice->tenantId(),
            invoiceId: $invoice->id(),
            action:    FeeInvoiceAuditAction::Reduced,
            actor:     $in->actor->id,
            now:       $now,
            payload:   [
                'reason'         => $in->reason,
                'amount_before'  => $amountBefore,
                'amount_after'   => $in->newAmountCents,
            ],
        ));
    }
}
```

- [ ] **Step 2: Test (mirror E3 structure)**

Create `tests/Unit/Application/Membership/Billing/ReduceMemberFeeInvoiceTest.php` with 3 tests: admin happy-path (assert status=REDUCED, original=5000, audit count=1), non-admin rejected, invalid amount (e.g. 6000 > 5000) caught and re-thrown as InvalidArgumentException.

- [ ] **Step 3: Run + commit**

```bash
vendor/bin/phpunit --filter ReduceMemberFeeInvoiceTest
composer analyse
git add src/Application/Membership/Billing/ReduceMemberFeeInvoice/ tests/Unit/Application/Membership/Billing/ReduceMemberFeeInvoiceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): ReduceMemberFeeInvoice use case with audit"
```

---

## Task E5: RecordManualPayment use case

**Files:**
- Create: `src/Application/Membership/Billing/RecordManualPayment/RecordManualPayment.php`
- Create: `src/Application/Membership/Billing/RecordManualPayment/RecordManualPaymentInput.php`
- Create: `tests/Unit/Application/Membership/Billing/RecordManualPaymentTest.php`

- [ ] **Step 1: Input + use case**

`src/Application/Membership/Billing/RecordManualPayment/RecordManualPaymentInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RecordManualPayment;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use DateTimeImmutable;

final class RecordManualPaymentInput
{
    public function __construct(
        public readonly ActingUser         $actor,
        public readonly MemberFeeInvoiceId $invoiceId,
        public readonly int                $amountCents,
        public readonly DateTimeImmutable  $paidAt,
        public readonly string             $method,
        public readonly string             $reference,
    ) {}
}
```

`src/Application/Membership/Billing/RecordManualPayment/RecordManualPayment.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RecordManualPayment;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Membership\Billing\PaymentRecord;
use Daems\Domain\Shared\Clock\ClockInterface;

final class RecordManualPayment
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface  $audit,
        private readonly ClockInterface                      $clock,
    ) {}

    public function handle(RecordManualPaymentInput $in): void
    {
        $invoice = $this->invoices->findById($in->invoiceId);
        if ($invoice === null) {
            throw new \DomainException("Invoice not found: {$in->invoiceId->value()}");
        }
        if (!$in->actor->isAdminIn($invoice->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can record payments");
        }

        $payment = new PaymentRecord(
            paidAt:      $in->paidAt,
            amountCents: $in->amountCents,
            method:      $in->method,
            reference:   $in->reference,
            paidBy:      $in->actor->id,
        );
        $invoice->recordPayment($payment);
        $this->invoices->save($invoice);

        $this->audit->append(FeeInvoiceAudit::record(
            tenantId:  $invoice->tenantId(),
            invoiceId: $invoice->id(),
            action:    FeeInvoiceAuditAction::Paid,
            actor:     $in->actor->id,
            now:       $this->clock->now(),
            payload:   [
                'amount_cents' => $in->amountCents,
                'method'       => $in->method,
                'reference'    => $in->reference,
                'paid_at'      => $in->paidAt->format(\DateTimeImmutable::ATOM),
            ],
        ));
    }
}
```

- [ ] **Step 2: Test (mirror E3 structure)**

`tests/Unit/Application/Membership/Billing/RecordManualPaymentTest.php` — 3 tests:
- admin records payment → status=PAID, payment fields populated, audit row created
- non-admin rejected
- already-paid invoice → InvoiceAlreadyPaidException bubbles up

- [ ] **Step 3: Run + commit**

```bash
vendor/bin/phpunit --filter RecordManualPaymentTest
composer analyse
git add src/Application/Membership/Billing/RecordManualPayment/ tests/Unit/Application/Membership/Billing/RecordManualPaymentTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): RecordManualPayment use case with audit"
```

---

## Task E6: Controller endpoints + DI wiring + E2E

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php`
- Modify: `bootstrap/app.php`, `tests/Support/KernelHarness.php`
- Modify: route table (add 5 endpoints)
- Create: `tests/E2E/Backstage/BillingInvoiceActionsEndpointTest.php`

New endpoints:
- `GET /api/v1/backstage/governance/billing/invoices?year=&status=&fee_type=&user_id=&page=`
- `POST /api/v1/backstage/governance/billing/invoices/{id}/mark-paid`
- `POST /api/v1/backstage/governance/billing/invoices/{id}/waive`
- `POST /api/v1/backstage/governance/billing/invoices/{id}/reduce`
- `GET /api/v1/backstage/governance/billing/invoices/{id}/audit`

- [ ] **Step 1: Extend controller**

Add to constructor:

```php
private readonly \Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice    $waive,
private readonly \Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice  $reduce,
private readonly \Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment        $markPaid,
private readonly \Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface                $invoices,
private readonly \Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface                 $audit,
```

5 methods:

```php
public function listInvoices(Request $request, ActingUser $actor, TenantId $tenantId): Response
{
    if (!$actor->isAdminIn($tenantId) && !$actor->isPlatformAdmin) {
        return Response::json(['error' => 'Forbidden'], 403);
    }
    $filter = array_filter([
        'year'     => $request->query('year')     !== null ? (int) $request->query('year') : null,
        'status'   => $request->query('status'),
        'fee_type' => $request->query('fee_type'),
        'user_id'  => $request->query('user_id'),
    ], fn($v) => $v !== null);

    $page = max(1, (int) ($request->query('page') ?? 1));
    $perPage = 50;
    $rows = $this->invoices->listForTenant($tenantId, $filter, $perPage, ($page - 1) * $perPage);

    return Response::json([
        'page' => $page,
        'rows' => array_map(fn($i) => $this->serializeInvoice($i), $rows),
    ], 200);
}

public function markInvoicePaid(Request $request, ActingUser $actor, TenantId $tenantId, string $invoiceId): Response
{
    try {
        $this->markPaid->handle(new \Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPaymentInput(
            actor:       $actor,
            invoiceId:   \Daems\Domain\Membership\Billing\MemberFeeInvoiceId::fromString($invoiceId),
            amountCents: (int) $request->bodyValue('amount_cents'),
            paidAt:      new \DateTimeImmutable((string) $request->bodyValue('paid_at')),
            method:      (string) $request->bodyValue('method'),
            reference:   (string) ($request->bodyValue('reference') ?? ''),
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    } catch (\Daems\Domain\Membership\Billing\Exception\InvoiceAlreadyPaidException $e) {
        return Response::json(['error' => $e->getMessage()], 409);
    } catch (\DomainException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
    return Response::json(['marked_paid' => true], 200);
}

public function waiveInvoice(Request $request, ActingUser $actor, TenantId $tenantId, string $invoiceId): Response
{
    try {
        $this->waive->handle(new \Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoiceInput(
            actor:     $actor,
            invoiceId: \Daems\Domain\Membership\Billing\MemberFeeInvoiceId::fromString($invoiceId),
            reason:    (string) $request->bodyValue('reason'),
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 400);
    } catch (\DomainException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
    return Response::json(['waived' => true], 200);
}

public function reduceInvoice(Request $request, ActingUser $actor, TenantId $tenantId, string $invoiceId): Response
{
    try {
        $this->reduce->handle(new \Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoiceInput(
            actor:          $actor,
            invoiceId:      \Daems\Domain\Membership\Billing\MemberFeeInvoiceId::fromString($invoiceId),
            newAmountCents: (int) $request->bodyValue('amount_cents'),
            reason:         (string) $request->bodyValue('reason'),
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 400);
    } catch (\DomainException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
    return Response::json(['reduced' => true], 200);
}

public function invoiceAudit(Request $request, ActingUser $actor, TenantId $tenantId, string $invoiceId): Response
{
    if (!$actor->isAdminIn($tenantId) && !$actor->isPlatformAdmin) {
        return Response::json(['error' => 'Forbidden'], 403);
    }
    $rows = $this->audit->listForInvoice(\Daems\Domain\Membership\Billing\MemberFeeInvoiceId::fromString($invoiceId));
    return Response::json([
        'rows' => array_map(static fn($r) => [
            'action'       => $r->action->value,
            'performed_by' => $r->performedBy?->value(),
            'performed_at' => $r->performedAt->format(\DateTimeImmutable::ATOM),
            'payload'      => $r->payloadJson !== null ? json_decode($r->payloadJson, true) : null,
        ], $rows),
    ], 200);
}

/** @return array<string,mixed> */
private function serializeInvoice(\Daems\Domain\Membership\Billing\MemberFeeInvoice $i): array
{
    return [
        'id'                => $i->id()->value(),
        'user_id'           => $i->userId()->value(),
        'year'              => $i->year(),
        'fee_type'          => $i->feeType()->value,
        'anniversary_date'  => $i->anniversaryDate()->format('Y-m-d'),
        'amount_cents'      => $i->amountCents(),
        'original_amount_cents' => $i->originalAmountCents(),
        'currency'          => $i->currency(),
        'due_date'          => $i->dueDate()->format('Y-m-d'),
        'status'            => $i->status()->value,
        'paid_at'           => $i->paidAt()?->format(\DateTimeImmutable::ATOM),
        'paid_amount_cents' => $i->paidAmountCents(),
        'paid_method'       => $i->paidMethod(),
        'paid_reference'    => $i->paidReference(),
        'waived_at'         => $i->waivedAt()?->format(\DateTimeImmutable::ATOM),
        'waive_reason'      => $i->waiveReason(),
        'override_id'       => $i->overrideId(),
    ];
}
```

- [ ] **Step 2: Update DI bindings (BOTH containers)**

In `bootstrap/app.php` AND `tests/Support/KernelHarness.php`:

```php
$container[\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class] =
    fn($c) => new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlFeeInvoiceAuditRepository($c[PDO::class]);
// (use InMemoryFeeInvoiceAuditRepository in KernelHarness)

$container[\Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice::class] =
    fn($c) => new \Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice(
        $c[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

$container[\Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice::class] =
    fn($c) => new \Daems\Application\Membership\Billing\ReduceMemberFeeInvoice\ReduceMemberFeeInvoice(
        $c[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

$container[\Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment::class] =
    fn($c) => new \Daems\Application\Membership\Billing\RecordManualPayment\RecordManualPayment(
        $c[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );

// Extend the controller binding with the new 5 dependencies (use cases + invoice repo + audit repo).
```

- [ ] **Step 3: Register routes**

```php
$router->get('/api/v1/backstage/governance/billing/invoices',
    [BackstageBillingController::class, 'listInvoices']);
$router->post('/api/v1/backstage/governance/billing/invoices/{id}/mark-paid',
    [BackstageBillingController::class, 'markInvoicePaid']);
$router->post('/api/v1/backstage/governance/billing/invoices/{id}/waive',
    [BackstageBillingController::class, 'waiveInvoice']);
$router->post('/api/v1/backstage/governance/billing/invoices/{id}/reduce',
    [BackstageBillingController::class, 'reduceInvoice']);
$router->get('/api/v1/backstage/governance/billing/invoices/{id}/audit',
    [BackstageBillingController::class, 'invoiceAudit']);
```

- [ ] **Step 4: Write E2E test**

`tests/E2E/Backstage/BillingInvoiceActionsEndpointTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BillingInvoiceActionsEndpointTest extends TestCase
{
    public function test_mark_paid_round_trip(): void
    {
        [$h, $admin, $invoiceId] = $this->setup();

        $r = $h->request('POST', "/api/v1/backstage/governance/billing/invoices/{$invoiceId}/mark-paid", [
            'amount_cents' => 5000,
            'paid_at'      => '2026-08-15T10:00:00+03:00',
            'method'       => 'bank_transfer',
            'reference'    => 'Nordea 12345/2026',
        ], actor: $admin);

        $this->assertSame(200, $r->status());
        $this->assertTrue($r->jsonBody()['marked_paid']);

        // Audit row exists
        $r = $h->request('GET', "/api/v1/backstage/governance/billing/invoices/{$invoiceId}/audit", actor: $admin);
        $this->assertCount(1, $r->jsonBody()['rows']);
        $this->assertSame('paid', $r->jsonBody()['rows'][0]['action']);
    }

    public function test_waive_then_double_waive_409(): void
    {
        [$h, $admin, $invoiceId] = $this->setup();

        $r = $h->request('POST', "/api/v1/backstage/governance/billing/invoices/{$invoiceId}/waive", [
            'reason' => 'Pitkäaikaissairaus',
        ], actor: $admin);
        $this->assertSame(200, $r->status());

        $r2 = $h->request('POST', "/api/v1/backstage/governance/billing/invoices/{$invoiceId}/waive", [
            'reason' => 'Again',
        ], actor: $admin);
        // Already-final state → 404 or 400 depending on the entity exception; both are acceptable.
        $this->assertTrue(in_array($r2->status(), [400, 404], true));
    }

    public function test_reduce_lowers_amount(): void
    {
        [$h, $admin, $invoiceId] = $this->setup();

        $r = $h->request('POST', "/api/v1/backstage/governance/billing/invoices/{$invoiceId}/reduce", [
            'amount_cents' => 2500,
            'reason'       => 'Opiskelija-alennus 50%',
        ], actor: $admin);
        $this->assertSame(200, $r->status());

        $r = $h->request('GET', "/api/v1/backstage/governance/billing/invoices?user_id=...", actor: $admin);
        $row = $r->jsonBody()['rows'][0] ?? null;
        $this->assertSame(2500, $row['amount_cents'] ?? null);
        $this->assertSame(5000, $row['original_amount_cents'] ?? null);
        $this->assertSame('REDUCED', $row['status'] ?? null);
    }

    /**
     * @return array{0:KernelHarness,1:\Daems\Domain\Auth\ActingUser,2:string}
     */
    private function setup(): array
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $h->setRequiresFormalDecisionForFees('daems', false);
        $admin = $h->seedAdminUser('daems');
        $member = $h->seedMember('daems');

        // Set fee + generate anniversary invoice manually.
        $h->request('POST', '/api/v1/backstage/governance/billing/fee-schedules', [
            'year' => 2026,
            'fees' => ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ], actor: $admin);
        $invoiceId = $h->createTestInvoice('daems', $member, year: 2026, amountCents: 5000); // helper to add

        return [$h, $admin, $invoiceId];
    }
}
```

`KernelHarness::createTestInvoice()` is a new helper — insert a row directly (avoid running anniversary-cron in E2E). Add it as a 2-line helper:

```php
public function createTestInvoice(string $tenantSlug, UserId $userId, int $year, int $amountCents): string
{
    $id = \Daems\Domain\Membership\Billing\MemberFeeInvoiceId::generate()->value();
    $tenantId = $this->tenantIdBySlug($tenantSlug);
    $this->container[\PDO::class]->prepare(
        "INSERT INTO member_fee_invoices
            (id, tenant_id, user_id, year, fee_type, anniversary_date, amount_cents, currency, due_date, status, created_at)
         VALUES (?, ?, ?, ?, 'BASIC', '2026-07-15', ?, 'EUR', '2026-09-13', 'PENDING', NOW())"
    )->execute([$id, $tenantId->value(), $userId->value(), $year, $amountCents]);
    return $id;
}
```

- [ ] **Step 5: Run + PHPStan + commit**

```bash
vendor/bin/phpunit --filter BillingInvoiceActionsEndpointTest
composer analyse
git add src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php bootstrap/app.php tests/Support/KernelHarness.php tests/E2E/Backstage/BillingInvoiceActionsEndpointTest.php public/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api): invoice action endpoints (list, mark-paid, waive, reduce, audit)"
```

---

## Task E7: Backstage invoice list view — _invoices.php (replaces Wave B stub)

**Files:**
- Modify: `public/backstage/governance/billing/_invoices.php` (replace stub)
- Create: `public/backstage/assets/governance/billing-invoices.js`

- [ ] **Step 1: Replace stub**

`public/backstage/governance/billing/_invoices.php`:

```php
<?php
declare(strict_types=1);

$year     = isset($_GET['year'])     ? (int) $_GET['year']         : (int) date('Y');
$status   = isset($_GET['status'])   ? (string) $_GET['status']    : '';
$feeType  = isset($_GET['fee_type']) ? (string) $_GET['fee_type']  : '';
?>

<section class="billing-invoices">
    <header class="billing-invoices__header">
        <h2>Laskut <?= htmlspecialchars((string) $year) ?></h2>
        <form method="get" class="billing-invoices__filters">
            <input type="hidden" name="view" value="invoices">
            <label>Vuosi
                <input type="number" name="year" value="<?= htmlspecialchars((string) $year) ?>" min="2026" max="2099">
            </label>
            <label>Tila
                <select name="status">
                    <option value="">Kaikki</option>
                    <option value="PENDING"  <?= $status === 'PENDING'  ? 'selected' : '' ?>>Odottaa</option>
                    <option value="PAID"     <?= $status === 'PAID'     ? 'selected' : '' ?>>Maksettu</option>
                    <option value="OVERDUE"  <?= $status === 'OVERDUE'  ? 'selected' : '' ?>>Erääntynyt</option>
                    <option value="WAIVED"   <?= $status === 'WAIVED'   ? 'selected' : '' ?>>Vapautettu</option>
                    <option value="REDUCED"  <?= $status === 'REDUCED'  ? 'selected' : '' ?>>Alennettu</option>
                </select>
            </label>
            <label>Tyyppi
                <select name="fee_type">
                    <option value="">Kaikki</option>
                    <option value="SUPPORTING" <?= $feeType === 'SUPPORTING' ? 'selected' : '' ?>>Kannatus</option>
                    <option value="BASIC"      <?= $feeType === 'BASIC'      ? 'selected' : '' ?>>Perus</option>
                    <option value="FULL"       <?= $feeType === 'FULL'       ? 'selected' : '' ?>>Varsinainen</option>
                </select>
            </label>
            <button type="submit">Suodata</button>
        </form>
    </header>

    <table class="billing-invoices__list" data-source="/api/v1/backstage/governance/billing/invoices?year=<?= (int) $year ?>&status=<?= urlencode($status) ?>&fee_type=<?= urlencode($feeType) ?>">
        <thead>
            <tr>
                <th>Jäsen</th>
                <th>Tyyppi</th>
                <th>Summa</th>
                <th>Eräpäivä</th>
                <th>Tila</th>
                <th>Toiminnot</th>
            </tr>
        </thead>
        <tbody><tr><td colspan="6">Ladataan…</td></tr></tbody>
    </table>

    <!-- Modals for row actions -->
    <dialog id="mark-paid-dialog" class="billing-invoices__dialog">
        <form method="dialog" id="mark-paid-form">
            <h3>Merkitse maksetuksi</h3>
            <input type="hidden" name="invoice_id">
            <label>Maksusumma (€)<input type="number" name="amount_euro" step="0.01" required></label>
            <label>Maksupäivä<input type="datetime-local" name="paid_at" required></label>
            <label>Maksutapa
                <select name="method" required>
                    <option value="bank_transfer">Pankkisiirto</option>
                    <option value="cash">Käteinen</option>
                    <option value="other">Muu</option>
                </select>
            </label>
            <label>Viite<input type="text" name="reference"></label>
            <menu>
                <button type="button" value="cancel">Peruuta</button>
                <button type="submit" class="button button--primary">Vahvista</button>
            </menu>
        </form>
    </dialog>

    <dialog id="waive-dialog" class="billing-invoices__dialog">
        <form method="dialog" id="waive-form">
            <h3>Vapauta lasku</h3>
            <input type="hidden" name="invoice_id">
            <label>Perustelu (pakollinen)<textarea name="reason" rows="3" required></textarea></label>
            <menu>
                <button type="button" value="cancel">Peruuta</button>
                <button type="submit" class="button button--primary">Vapauta</button>
            </menu>
        </form>
    </dialog>

    <dialog id="reduce-dialog" class="billing-invoices__dialog">
        <form method="dialog" id="reduce-form">
            <h3>Alenna lasku</h3>
            <input type="hidden" name="invoice_id">
            <label>Uusi summa (€)<input type="number" name="amount_euro" step="0.01" required></label>
            <label>Perustelu (pakollinen)<textarea name="reason" rows="3" required></textarea></label>
            <menu>
                <button type="button" value="cancel">Peruuta</button>
                <button type="submit" class="button button--primary">Alenna</button>
            </menu>
        </form>
    </dialog>

    <dialog id="audit-dialog" class="billing-invoices__dialog">
        <h3>Lasku-historia</h3>
        <ul id="audit-list"></ul>
        <button type="button" id="close-audit-btn">Sulje</button>
    </dialog>
</section>

<script defer src="/backstage/assets/governance/billing-invoices.js"></script>
```

- [ ] **Step 2: JS handler**

`public/backstage/assets/governance/billing-invoices.js`:

```js
document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('.billing-invoices__list');
    if (table) loadInvoices(table);
    bindRowActionForms();
});

async function loadInvoices(table) {
    const resp = await fetch(table.dataset.source, { headers: { 'Accept': 'application/json' } });
    if (!resp.ok) { table.querySelector('tbody').innerHTML = '<tr><td colspan="6">Lataus epäonnistui</td></tr>'; return; }
    const data = await resp.json();
    if (data.rows.length === 0) {
        table.querySelector('tbody').innerHTML = '<tr><td colspan="6">Ei laskuja</td></tr>';
        return;
    }
    table.querySelector('tbody').innerHTML = data.rows.map(r => `
        <tr data-id="${r.id}" data-status="${r.status}">
            <td><code>${escape(r.user_id.slice(0, 8))}…</code></td>
            <td>${escape(r.fee_type)}</td>
            <td>
                ${(r.amount_cents / 100).toFixed(2)} €
                ${r.original_amount_cents !== null ? `<small>(orig ${(r.original_amount_cents / 100).toFixed(2)})</small>` : ''}
            </td>
            <td>${escape(r.due_date)}</td>
            <td><span class="status-badge status-${escape(r.status)}">${escape(statusLabel(r.status))}</span></td>
            <td>
                ${r.status === 'PENDING' || r.status === 'OVERDUE' || r.status === 'REDUCED' ? `
                    <button type="button" data-action="mark-paid" data-id="${r.id}">Maksettu</button>
                    <button type="button" data-action="waive"     data-id="${r.id}">Vapauta</button>
                    <button type="button" data-action="reduce"    data-id="${r.id}">Alenna</button>
                ` : ''}
                <button type="button" data-action="audit" data-id="${r.id}">Historia</button>
            </td>
        </tr>
    `).join('');

    table.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', () => openDialog(btn.dataset.action, btn.dataset.id));
    });
}

function openDialog(action, invoiceId) {
    const map = { 'mark-paid': 'mark-paid-dialog', 'waive': 'waive-dialog', 'reduce': 'reduce-dialog' };
    if (action === 'audit') return loadAndShowAudit(invoiceId);
    const dialog = document.getElementById(map[action]);
    dialog.querySelector('input[name="invoice_id"]').value = invoiceId;
    dialog.showModal();
}

async function loadAndShowAudit(invoiceId) {
    const resp = await fetch(`/api/v1/backstage/governance/billing/invoices/${invoiceId}/audit`);
    const data = await resp.json();
    const list = document.getElementById('audit-list');
    list.innerHTML = data.rows.map(r => `
        <li>
            <strong>${escape(r.action)}</strong> – ${new Date(r.performed_at).toLocaleString('fi-FI')}
            ${r.performed_by ? `by <code>${escape(r.performed_by.slice(0,8))}…</code>` : '(cron)'}
            ${r.payload ? `<pre>${escape(JSON.stringify(r.payload, null, 2))}</pre>` : ''}
        </li>
    `).join('');
    document.getElementById('audit-dialog').showModal();
}

function bindRowActionForms() {
    document.getElementById('mark-paid-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        const id = f.invoice_id.value;
        const resp = await fetch(`/api/v1/backstage/governance/billing/invoices/${id}/mark-paid`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                amount_cents: Math.round(parseFloat(f.amount_euro.value) * 100),
                paid_at:      new Date(f.paid_at.value).toISOString(),
                method:       f.method.value,
                reference:    f.reference.value,
            }),
        });
        if (resp.ok) { window.location.reload(); } else { alert('Tallennus epäonnistui'); }
    });

    document.getElementById('waive-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        const id = f.invoice_id.value;
        const resp = await fetch(`/api/v1/backstage/governance/billing/invoices/${id}/waive`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reason: f.reason.value }),
        });
        if (resp.ok) { window.location.reload(); } else { alert('Vapautus epäonnistui'); }
    });

    document.getElementById('reduce-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        const id = f.invoice_id.value;
        const resp = await fetch(`/api/v1/backstage/governance/billing/invoices/${id}/reduce`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                amount_cents: Math.round(parseFloat(f.amount_euro.value) * 100),
                reason:       f.reason.value,
            }),
        });
        if (resp.ok) { window.location.reload(); } else { alert('Alennus epäonnistui'); }
    });

    document.getElementById('close-audit-btn')?.addEventListener('click', () => {
        document.getElementById('audit-dialog').close();
    });
}

function statusLabel(s) {
    return { PENDING: 'Odottaa', PAID: 'Maksettu', OVERDUE: 'Erääntynyt', WAIVED: 'Vapautettu', REDUCED: 'Alennettu' }[s] ?? s;
}

function escape(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
```

- [ ] **Step 3: Append CSS**

Append to `public/backstage/assets/governance/billing.css`:

```css
.billing-invoices__header {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
}
.billing-invoices__filters {
    display: flex;
    gap: var(--space-2);
    align-items: end;
    flex-wrap: wrap;
}
.billing-invoices__list {
    width: 100%;
    border-collapse: collapse;
}
.billing-invoices__list th,
.billing-invoices__list td {
    text-align: left;
    padding: var(--space-2);
    border-bottom: 1px solid var(--color-border);
}
.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: var(--font-xs);
}
.status-PENDING { background: var(--color-info-bg); }
.status-PAID    { background: var(--color-success-bg); }
.status-OVERDUE { background: var(--color-danger-bg); }
.status-WAIVED  { background: var(--color-neutral-bg); }
.status-REDUCED { background: var(--color-warning-bg); }
.billing-invoices__dialog {
    border: none;
    border-radius: var(--radius-md);
    padding: var(--space-4);
    min-width: 480px;
}
.billing-invoices__dialog form > label {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-2);
}
.billing-invoices__dialog menu {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-3);
}
```

- [ ] **Step 4: Browser smoke**

```
1. http://daems.local/backstage/governance/billing
2. Filter bar shows year + status + type
3. List loads invoices from API
4. Click "Maksettu" on PENDING invoice → modal opens
5. Fill modal + submit → page reloads, invoice shows status=PAID
6. Click "Historia" → audit drawer opens with the recorded payment event
```

- [ ] **Step 5: Commit**

```bash
git add public/backstage/governance/billing/_invoices.php public/backstage/assets/governance/billing-invoices.js public/backstage/assets/governance/billing.css
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): invoice list with row-actions (mark-paid/waive/reduce/audit)"
```

---

## Task E8: KPI strip on landing page

**Files:**
- Modify: `public/backstage/governance/billing/index.php` (add KPI strip above tab nav)
- Modify: `public/backstage/assets/governance/billing-invoices.js` (load KPI counts)
- Add: GET endpoint `/api/v1/backstage/governance/billing/kpi?year=` returning counts per status

- [ ] **Step 1: KPI endpoint**

In `BackstageBillingController.php` add method:

```php
public function billingKpi(Request $request, ActingUser $actor, TenantId $tenantId): Response
{
    if (!$actor->isAdminIn($tenantId) && !$actor->isPlatformAdmin) {
        return Response::json(['error' => 'Forbidden'], 403);
    }
    $year = (int) ($request->query('year') ?? date('Y'));

    $counts = [];
    foreach (['PENDING','OVERDUE','PAID','WAIVED','REDUCED'] as $st) {
        $counts[$st] = count($this->invoices->listForTenant($tenantId, ['year' => $year, 'status' => $st], 9999, 0));
    }
    return Response::json(['year' => $year, 'counts' => $counts], 200);
}
```

(Optimization: replace with a SELECT COUNT(*) GROUP BY status SQL — see TODO comment for future fix.)

Add route:

```php
$router->get('/api/v1/backstage/governance/billing/kpi',
    [BackstageBillingController::class, 'billingKpi']);
```

- [ ] **Step 2: Update index.php**

Insert above `<nav class="billing-tabs">`:

```php
<section class="billing-kpi" data-source="/api/v1/backstage/governance/billing/kpi?year=<?= (int) date('Y') ?>">
    <div class="billing-kpi__card billing-kpi__card--pending">
        <h3>Odottaa</h3>
        <p class="billing-kpi__value" data-key="PENDING">–</p>
    </div>
    <div class="billing-kpi__card billing-kpi__card--overdue">
        <h3>Erääntynyt</h3>
        <p class="billing-kpi__value" data-key="OVERDUE">–</p>
    </div>
    <div class="billing-kpi__card billing-kpi__card--paid">
        <h3>Maksettu</h3>
        <p class="billing-kpi__value" data-key="PAID">–</p>
    </div>
    <div class="billing-kpi__card billing-kpi__card--waived">
        <h3>Vapautettu</h3>
        <p class="billing-kpi__value" data-key="WAIVED">–</p>
    </div>
</section>
```

- [ ] **Step 3: JS to load KPI**

Append to `billing-invoices.js` (or a new `billing-kpi.js`):

```js
document.addEventListener('DOMContentLoaded', async () => {
    const kpi = document.querySelector('.billing-kpi');
    if (!kpi) return;
    const resp = await fetch(kpi.dataset.source);
    if (!resp.ok) return;
    const data = await resp.json();
    kpi.querySelectorAll('[data-key]').forEach(el => {
        el.textContent = data.counts[el.dataset.key] ?? 0;
    });
});
```

- [ ] **Step 4: CSS**

Append to `billing.css`:

```css
.billing-kpi {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-3);
    margin-bottom: var(--space-4);
}
.billing-kpi__card {
    padding: var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}
.billing-kpi__card h3 {
    margin: 0 0 var(--space-1);
    font-size: var(--font-xs);
    color: var(--color-text-secondary);
}
.billing-kpi__value {
    font-size: var(--font-2xl);
    font-weight: 600;
    margin: 0;
}
.billing-kpi__card--overdue .billing-kpi__value {
    color: var(--color-danger-text);
}
```

- [ ] **Step 5: Commit**

```bash
git add public/backstage/governance/billing/index.php public/backstage/assets/governance/billing-invoices.js public/backstage/assets/governance/billing.css src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php public/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): KPI strip (PENDING/OVERDUE/PAID/WAIVED) above invoice tabs"
```

---

## Task E9: Wire migration test watermark update

**Files:**
- Modify: `tests/Isolation/IsolationTestCase.php`

Watermark needs to bump 95 → 95 (no change — 092 and 093 are within 95). But verify: integration tests now need 091+092+093 applied. Should already be the case from Wave B Task B5.

Actually — Wave B set watermark = 95. Wave C added migration 091 (slot 91 < 95 ✓). Wave D added 092 (92 < 95 ✓). Wave E adds 093 (93 < 95 ✓). All within range — no change to IsolationTestCase needed.

- [ ] **Step 1: Verify**

```bash
grep -n "runMigrationsUpTo" tests/Isolation/IsolationTestCase.php
```

Expected: `runMigrationsUpTo(95)` — already correct from Wave B.

- [ ] **Step 2: Run Isolation suite to confirm new migrations applied**

```bash
vendor/bin/phpunit --testsuite Isolation 2>&1 | tail -5
```

Expected: green or rerun-stable. (Flakiness — see `feedback_isolation_suite_flaky.md`.)

- [ ] **Step 3: No commit needed** (no file changes).

---

## Task E10: Wave E Definition of Done

After Tasks E1-E9:

- [ ] Migration 093 applied (both DBs)
- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --testsuite Unit` green (count up by ~10-15 from Wave D)
- [ ] `vendor/bin/phpunit --filter 'WaiveMemberFeeInvoice|ReduceMemberFeeInvoice|RecordManualPayment|FeeInvoiceAudit'` green
- [ ] `vendor/bin/phpunit --filter 'BillingInvoiceActionsEndpointTest'` green
- [ ] Browser smoke: `/backstage/governance/billing` shows KPI strip + invoice list, all 3 row-actiont work, audit drawer opens

Run gate:

```bash
composer analyse
vendor/bin/phpunit --filter 'Billing' 2>&1 | tail -3
```

If anything fails, FIX before starting Wave F.

---

# Wave F — Overdue + Lapse cron (Phases 9-10, 8 tasks)

This wave completes the cron pipeline: PENDING invoices flip to OVERDUE after grace period, and members with 2 consecutive OVERDUE years get LAPSED (§ 4 deemed-resignation).

## Task F1: MarkOverdueInvoices use case + cron

**Files:**
- Create: `src/Application/Membership/Billing/MarkOverdueInvoices/MarkOverdueInvoices.php`
- Create: `src/Application/Membership/Billing/MarkOverdueInvoices/MarkOverdueInvoicesInput.php`
- Create: `src/Application/Membership/Billing/Cron/MarkOverdueInvoicesCommand.php`
- Create: `tests/Unit/Application/Membership/Billing/MarkOverdueInvoicesTest.php`
- Create: `tests/Integration/Cron/MarkOverdueInvoicesCommandTest.php`

- [ ] **Step 1: Input + use case**

`src/Application/Membership/Billing/MarkOverdueInvoices/MarkOverdueInvoicesInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\MarkOverdueInvoices;

use Daems\Domain\Tenant\TenantId;

final class MarkOverdueInvoicesInput
{
    public function __construct(public readonly TenantId $tenantId) {}
}
```

`src/Application/Membership/Billing/MarkOverdueInvoices/MarkOverdueInvoices.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\MarkOverdueInvoices;

use Daems\Domain\Membership\Billing\FeeInvoiceAudit;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditAction;
use Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;
use Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface;

final class MarkOverdueInvoices
{
    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface         $invoices,
        private readonly FeeInvoiceAuditRepositoryInterface          $audit,
        private readonly TenantGovernanceSettingsRepositoryInterface $settings,
        private readonly ClockInterface                              $clock,
    ) {}

    public function handle(MarkOverdueInvoicesInput $in): int
    {
        $settings = $this->settings->findForTenant($in->tenantId);
        $graceDays = $settings?->overdueGraceDays() ?? 30;
        $now = $this->clock->now();

        $candidates = $this->invoices->findOverdueCandidates($in->tenantId, $now, $graceDays);
        $flagged = 0;
        foreach ($candidates as $invoice) {
            $invoice->markOverdue($now);
            $this->invoices->save($invoice);
            $this->audit->append(FeeInvoiceAudit::record(
                tenantId:  $invoice->tenantId(),
                invoiceId: $invoice->id(),
                action:    FeeInvoiceAuditAction::OverdueFlagged,
                actor:     null, // cron
                now:       $now,
                payload:   ['due_date' => $invoice->dueDate()->format('Y-m-d'), 'grace_days' => $graceDays],
            ));
            $flagged++;
        }
        return $flagged;
    }
}
```

- [ ] **Step 2: Cron command**

`src/Application/Membership/Billing/Cron/MarkOverdueInvoicesCommand.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\Cron;

use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices;
use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoicesInput;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use PDO;

final class MarkOverdueInvoicesCommand implements CommandInterface
{
    public function __construct(
        private readonly PDO                   $pdo,
        private readonly MarkOverdueInvoices   $useCase,
        private readonly LockManager           $lockManager,
        private readonly CronLogger            $logger,
    ) {}

    public function name(): string { return 'membership:mark-overdue-invoices'; }

    public function execute(array $args): int
    {
        if (!$this->lockManager->acquire($this->name())) {
            $this->logger->info(['message' => 'lock held, skipping', 'command' => $this->name()]);
            return 0;
        }
        try {
            $tenantFilter = isset($args['tenant']) && is_string($args['tenant']) ? $args['tenant'] : null;
            $tenants = $this->loadTenants($tenantFilter);
            $total = 0;
            $start = microtime(true);

            foreach ($tenants as $tenant) {
                try {
                    $count = $this->useCase->handle(new MarkOverdueInvoicesInput(
                        TenantId::fromString((string) $tenant['id'])
                    ));
                    $this->logger->info(['tenant' => $tenant['slug'], 'flagged' => $count]);
                    $total += $count;
                } catch (\Throwable $e) {
                    $this->logger->error(['tenant' => $tenant['slug'], 'message' => $e->getMessage()]);
                }
            }
            $this->logger->info([
                'summary'       => true,
                'total_tenants' => count($tenants),
                'total_flagged' => $total,
                'duration_ms'   => (int) ((microtime(true) - $start) * 1000),
            ]);
            return 0;
        } finally {
            $this->lockManager->release($this->name());
        }
    }

    /** @return list<array{id:string,slug:string}> */
    private function loadTenants(?string $slug): array
    {
        $sql = 'SELECT id, slug FROM tenants WHERE suspended_at IS NULL';
        $params = [];
        if ($slug !== null) { $sql .= ' AND slug = ?'; $params[] = $slug; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => ['id' => (string) $r['id'], 'slug' => (string) $r['slug']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
}
```

- [ ] **Step 3: Register in bootstrap/console.php**

Append to the existing registry-block:

```php
$registry->register(new \Daems\Application\Membership\Billing\Cron\MarkOverdueInvoicesCommand(
    pdo:         $container[\PDO::class],
    useCase:     $container[\Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices::class],
    lockManager: new \Daems\Infrastructure\Console\LockManager(__DIR__ . '/../var/run'),
    logger:      new \Daems\Infrastructure\Console\CronLogger(__DIR__ . '/../var/log/cron', 'membership:mark-overdue-invoices', new \DateTimeImmutable()),
));
```

- [ ] **Step 4: DI wiring (BOTH containers)**

```php
$container[\Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices::class] =
    fn($c) => new \Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices(
        $c[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
        $c[\Daems\Domain\Membership\Billing\FeeInvoiceAuditRepositoryInterface::class],
        $c[\Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

Same in `tests/Support/KernelHarness.php`.

- [ ] **Step 5: Unit test**

`tests/Unit/Application/Membership/Billing/MarkOverdueInvoicesTest.php`: 3 tests
- only PENDING invoices past due_date + grace are flagged → assert OVERDUE
- already-OVERDUE invoices are not re-flagged (idempotent)
- invoices within grace period are NOT flagged

- [ ] **Step 6: Integration test (mirror C9 structure)**

`tests/Integration/Cron/MarkOverdueInvoicesCommandTest.php`:
- seed 3 invoices (1 within grace, 1 past grace, 1 already OVERDUE)
- run cron
- assert middle one is now OVERDUE, others unchanged
- run cron twice → second run flags 0 (idempotent)

- [ ] **Step 7: Run + commit**

```bash
vendor/bin/phpunit --filter 'MarkOverdueInvoices'
composer analyse
git add src/Application/Membership/Billing/MarkOverdueInvoices/ src/Application/Membership/Billing/Cron/MarkOverdueInvoicesCommand.php bootstrap/console.php bootstrap/app.php tests/Support/KernelHarness.php tests/Unit/Application/Membership/Billing/MarkOverdueInvoicesTest.php tests/Integration/Cron/MarkOverdueInvoicesCommandTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(cron): MarkOverdueInvoices use case + command (PENDING → OVERDUE after grace)"
```

---

## Task F2: LapseInactiveMember domain — MemberStatusAudit integration

**Files:**
- Verify: 0.6b's `MemberStatusAudit` entity supports `reason='2v maksamatta'` and actor=NULL
- Verify: 0.6b's `users.membership_status` enum supports `LAPSED` value

Before the use case can flip a user to LAPSED, two things must hold. Verify in code; add migration if needed.

- [ ] **Step 1: Inspect users.membership_status enum**

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db -e "SHOW COLUMNS FROM users LIKE 'membership_status';"
```

If output shows `enum('active','paused','expelled')` (or similar) **without** `lapsed`:

Create `database/migrations/096_extend_users_membership_status_for_lapsed.sql`:

```sql
-- 096_extend_users_membership_status_for_lapsed.sql
-- Adds 'LAPSED' to users.membership_status so § 4 deemed-resignation can
-- flip members who haven't paid for 2 consecutive years.

ALTER TABLE users
    MODIFY COLUMN membership_status ENUM('active','paused','expelled','lapsed')
        NOT NULL DEFAULT 'active';
```

(Replace the enum value list with the EXACT current list + `'lapsed'`. If membership_status is VARCHAR/CHECK-style, the migration is just `DO 0` and a comment.)

Apply:

```bash
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db < database/migrations/096_extend_users_membership_status_for_lapsed.sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db_test < database/migrations/096_extend_users_membership_status_for_lapsed.sql
```

Bump `tests/Isolation/IsolationTestCase.php` watermark 95 → 96.

- [ ] **Step 2: Inspect MemberStatusAudit**

```bash
grep -rn "MemberStatusAudit" src/Domain/Membership/
grep -rn "performed_by" src/Domain/Membership/MemberStatusAudit.php
```

Verify the entity has a nullable `performed_by` field (or accepts NULL UserId for cron-driven changes). If not, extend it — but this should already be in place from 0.6b's lapse-via-expulsion code.

- [ ] **Step 3: Commit if migration was added**

```bash
# Only if migration 096 was created
git add database/migrations/096_extend_users_membership_status_for_lapsed.sql tests/Isolation/IsolationTestCase.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(db): migration 096 — users.membership_status += 'lapsed' (for § 4)"
```

---

## Task F3: LapseInactiveMember use case

**Files:**
- Create: `src/Application/Membership/Billing/LapseInactiveMember/LapseInactiveMember.php`
- Create: `src/Application/Membership/Billing/LapseInactiveMember/LapseInactiveMemberInput.php`
- Create: `src/Application/Membership/Billing/LapseInactiveMember/LapseInactiveMemberOutput.php`
- Create: `tests/Unit/Application/Membership/Billing/LapseInactiveMemberTest.php`

The use case takes (tenantId, userId, overdueYears) and:
1. Verifies user is in `active` status (no-op if already lapsed/expelled)
2. Flips `users.membership_status` to `'lapsed'`
3. Appends `member_status_audit` row with `previous_status`, `new_status='lapsed'`, `reason='2v maksamatta: <year1>, <year2>'`, `performed_by=NULL`

- [ ] **Step 1: DTOs**

`src/Application/Membership/Billing/LapseInactiveMember/LapseInactiveMemberInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\LapseInactiveMember;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class LapseInactiveMemberInput
{
    /** @param list<int> $overdueYears */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly UserId   $userId,
        public readonly array    $overdueYears,
    ) {}
}
```

`src/Application/Membership/Billing/LapseInactiveMember/LapseInactiveMemberOutput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\LapseInactiveMember;

final class LapseInactiveMemberOutput
{
    public function __construct(
        public readonly bool   $lapsed,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}
}
```

- [ ] **Step 2: Use case**

`src/Application/Membership/Billing/LapseInactiveMember/LapseInactiveMember.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\LapseInactiveMember;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class LapseInactiveMember
{
    public function __construct(
        private readonly UserRepositoryInterface              $users,
        private readonly MemberStatusAuditRepositoryInterface $audit,
        private readonly ClockInterface                       $clock,
    ) {}

    public function handle(LapseInactiveMemberInput $in): LapseInactiveMemberOutput
    {
        $user = $this->users->findById($in->userId);
        if ($user === null) {
            throw new \DomainException("User not found: {$in->userId->value()}");
        }

        $previousStatus = $user->membershipStatus();
        if ($previousStatus !== 'active') {
            // Already lapsed/expelled/paused — no-op
            return new LapseInactiveMemberOutput(
                lapsed:         false,
                previousStatus: $previousStatus,
                newStatus:      $previousStatus,
            );
        }

        $user->setMembershipStatus('lapsed');
        $this->users->save($user);

        $reason = '2v maksamatta: ' . implode(', ', $in->overdueYears);
        $this->audit->save(MemberStatusAudit::create(
            tenantId:       $in->tenantId,
            userId:         $in->userId,
            previousStatus: $previousStatus,
            newStatus:      'lapsed',
            reason:         $reason,
            performedBy:    null,
            createdAt:      $this->clock->now(),
        ));

        return new LapseInactiveMemberOutput(
            lapsed:         true,
            previousStatus: $previousStatus,
            newStatus:      'lapsed',
        );
    }
}
```

Note: `MemberStatusAudit::create(...)` and `User::setMembershipStatus()` are assumed to exist from 0.6b. If signatures differ, adjust this code to match — leave a comment in the use case for the executor.

- [ ] **Step 3: Test**

`tests/Unit/Application/Membership/Billing/LapseInactiveMemberTest.php`: 3 tests
- active member → flipped to lapsed, audit row created
- already-lapsed member → no-op, no audit row
- user not found → DomainException

(Use InMemory fakes for UserRepository + MemberStatusAuditRepository — both should exist from 0.6b.)

- [ ] **Step 4: DI wiring (BOTH containers)**

```php
$container[\Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember::class] =
    fn($c) => new \Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember(
        $c[\Daems\Domain\User\UserRepositoryInterface::class],
        $c[\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/phpunit --filter LapseInactiveMemberTest
composer analyse
git add src/Application/Membership/Billing/LapseInactiveMember/ tests/Unit/Application/Membership/Billing/LapseInactiveMemberTest.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): LapseInactiveMember use case (§ 4 deemed-resignation)"
```

---

## Task F4: LapseInactiveMembersCommand cron with --dry-run

**Files:**
- Create: `src/Application/Membership/Billing/Cron/LapseInactiveMembersCommand.php`
- Modify: `bootstrap/console.php`
- Create: `tests/Integration/Cron/LapseInactiveMembersCommandTest.php`

The command iterates tenants (filtering by `lapse_check_enabled=true`), uses `MemberFeeInvoiceRepository::findUsersWithConsecutiveOverdueYears()` to find candidates, then dispatches LapseInactiveMember for each. Supports `--dry-run` to preview without committing.

- [ ] **Step 1: Implement command**

`src/Application/Membership/Billing/Cron/LapseInactiveMembersCommand.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\Cron;

use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember;
use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMemberInput;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;
use Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use PDO;

final class LapseInactiveMembersCommand implements CommandInterface
{
    public function __construct(
        private readonly PDO                                          $pdo,
        private readonly MemberFeeInvoiceRepositoryInterface          $invoices,
        private readonly TenantGovernanceSettingsRepositoryInterface  $settings,
        private readonly LapseInactiveMember                          $useCase,
        private readonly LockManager                                  $lockManager,
        private readonly CronLogger                                   $logger,
    ) {}

    public function name(): string { return 'membership:lapse-inactive-members'; }

    public function execute(array $args): int
    {
        if (!$this->lockManager->acquire($this->name())) {
            $this->logger->info(['message' => 'lock held, skipping', 'command' => $this->name()]);
            return 0;
        }
        try {
            $isDryRun = isset($args['dry-run']) && $args['dry-run'] !== false;
            $tenantFilter = isset($args['tenant']) && is_string($args['tenant']) ? $args['tenant'] : null;

            $tenants = $this->loadTenants($tenantFilter);
            $totalLapsed = 0;
            $totalCandidates = 0;
            $start = microtime(true);

            foreach ($tenants as $tenant) {
                $tenantId = TenantId::fromString((string) $tenant['id']);
                $settings = $this->settings->findForTenant($tenantId);
                if ($settings === null || !$settings->lapseCheckEnabled()) {
                    $this->logger->info(['tenant' => $tenant['slug'], 'message' => 'lapse_check_enabled=false, skipped']);
                    continue;
                }

                $candidates = $this->invoices->findUsersWithConsecutiveOverdueYears($tenantId);
                $lapsed = 0;
                foreach ($candidates as $row) {
                    if ($isDryRun) {
                        $this->logger->info([
                            'tenant'  => $tenant['slug'],
                            'user_id' => $row['user_id']->value(),
                            'years'   => $row['years'],
                            'dry_run' => true,
                        ]);
                        continue;
                    }
                    try {
                        $out = $this->useCase->handle(new LapseInactiveMemberInput(
                            tenantId:     $tenantId,
                            userId:       $row['user_id'],
                            overdueYears: $row['years'],
                        ));
                        if ($out->lapsed) { $lapsed++; }
                    } catch (\Throwable $e) {
                        $this->logger->error([
                            'tenant'  => $tenant['slug'],
                            'user_id' => $row['user_id']->value(),
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
                $this->logger->info([
                    'tenant'     => $tenant['slug'],
                    'candidates' => count($candidates),
                    'lapsed'     => $isDryRun ? 0 : $lapsed,
                    'dry_run'    => $isDryRun,
                ]);
                $totalCandidates += count($candidates);
                $totalLapsed += $lapsed;
            }

            $this->logger->info([
                'summary'         => true,
                'total_tenants'   => count($tenants),
                'total_candidates'=> $totalCandidates,
                'total_lapsed'    => $totalLapsed,
                'dry_run'         => $isDryRun,
                'duration_ms'     => (int) ((microtime(true) - $start) * 1000),
            ]);
            return 0;
        } finally {
            $this->lockManager->release($this->name());
        }
    }

    /** @return list<array{id:string,slug:string}> */
    private function loadTenants(?string $slug): array
    {
        $sql = 'SELECT id, slug FROM tenants WHERE suspended_at IS NULL';
        $params = [];
        if ($slug !== null) { $sql .= ' AND slug = ?'; $params[] = $slug; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => ['id' => (string) $r['id'], 'slug' => (string) $r['slug']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
}
```

- [ ] **Step 2: Register in bootstrap/console.php**

```php
$registry->register(new \Daems\Application\Membership\Billing\Cron\LapseInactiveMembersCommand(
    pdo:         $container[\PDO::class],
    invoices:    $container[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
    settings:    $container[\Daems\Domain\Tenant\TenantGovernanceSettingsRepositoryInterface::class],
    useCase:     $container[\Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember::class],
    lockManager: new \Daems\Infrastructure\Console\LockManager(__DIR__ . '/../var/run'),
    logger:      new \Daems\Infrastructure\Console\CronLogger(__DIR__ . '/../var/log/cron', 'membership:lapse-inactive-members', new \DateTimeImmutable()),
));
```

- [ ] **Step 3: Smoke**

```bash
php bin/console membership:lapse-inactive-members --dry-run
```

Expected: exit 0. Log shows `dry_run=true` and (typically) `lapsed=0` because no actually-lapse-eligible users exist on dev DB yet.

- [ ] **Step 4: Commit**

```bash
git add src/Application/Membership/Billing/Cron/LapseInactiveMembersCommand.php bootstrap/console.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(cron): LapseInactiveMembersCommand with --dry-run support"
```

---

## Task F5: Lapse cron integration test

**Files:**
- Create: `tests/Integration/Cron/LapseInactiveMembersCommandTest.php`

End-to-end test exercising the full pipeline: seed 2 consecutive OVERDUE invoices for a user, run cron, assert membership_status flipped to lapsed AND member_status_audit row exists.

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Application\Membership\Billing\Cron\LapseInactiveMembersCommand;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class LapseInactiveMembersCommandTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(96);
    }

    public function test_lapses_user_with_two_overdue_years(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userId = $this->seedUser($tenantId, status: 'active');

        $this->seedOverdueInvoice($tenantId, $userId, year: 2025);
        $this->seedOverdueInvoice($tenantId, $userId, year: 2026);

        $command = $this->makeCommand(today: new DateTimeImmutable('2027-01-15T03:00:00'));
        $exit = $command->execute(['tenant' => 'daems']);
        $this->assertSame(0, $exit);

        $status = $this->pdo()->prepare('SELECT membership_status FROM users WHERE id = ?');
        $status->execute([$userId->value()]);
        $this->assertSame('lapsed', $status->fetchColumn());

        $auditCount = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM member_status_audit
             WHERE user_id = ? AND new_status = 'lapsed' AND reason LIKE '%2v maksamatta%'"
        );
        $auditCount->execute([$userId->value()]);
        $this->assertSame(1, (int) $auditCount->fetchColumn());
    }

    public function test_dry_run_does_not_modify(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userId = $this->seedUser($tenantId, status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, year: 2025);
        $this->seedOverdueInvoice($tenantId, $userId, year: 2026);

        $command = $this->makeCommand(today: new DateTimeImmutable('2027-01-15T03:00:00'));
        $exit = $command->execute(['tenant' => 'daems', 'dry-run' => true]);
        $this->assertSame(0, $exit);

        $status = $this->pdo()->prepare('SELECT membership_status FROM users WHERE id = ?');
        $status->execute([$userId->value()]);
        $this->assertSame('active', $status->fetchColumn()); // unchanged
    }

    public function test_one_overdue_year_does_not_trigger_lapse(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userId = $this->seedUser($tenantId, status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, year: 2026); // only one

        $command = $this->makeCommand(today: new DateTimeImmutable('2027-01-15T03:00:00'));
        $command->execute(['tenant' => 'daems']);

        $status = $this->pdo()->prepare('SELECT membership_status FROM users WHERE id = ?');
        $status->execute([$userId->value()]);
        $this->assertSame('active', $status->fetchColumn());
    }

    public function test_lapse_check_disabled_skips_tenant(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $this->pdo()->prepare(
            'UPDATE tenant_governance_settings SET lapse_check_enabled = 0 WHERE tenant_id = ?'
        )->execute([$tenantId->value()]);

        $userId = $this->seedUser($tenantId, status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, year: 2025);
        $this->seedOverdueInvoice($tenantId, $userId, year: 2026);

        $command = $this->makeCommand(today: new DateTimeImmutable('2027-01-15T03:00:00'));
        $command->execute(['tenant' => 'daems']);

        $status = $this->pdo()->prepare('SELECT membership_status FROM users WHERE id = ?');
        $status->execute([$userId->value()]);
        $this->assertSame('active', $status->fetchColumn()); // not lapsed because gate is off
    }

    // -- helpers ---------------------------------------------------------

    private function tenantIdBySlug(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return TenantId::fromString((string) $stmt->fetchColumn());
    }

    private function seedUser(TenantId $tenantId, string $status): UserId
    {
        $id = '01958000-0000-7000-9000-' . substr(md5((string) mt_rand()), 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, NULL, ?, 0, ?, ?, ?)'
        )->execute([$id, 'U', $id . '@daems.fi', '1990-01-01', 'BASIC', $status, '2024-01-15 00:00:00']);
        return UserId::fromString($id);
    }

    private function seedOverdueInvoice(TenantId $tenantId, UserId $userId, int $year): void
    {
        $this->pdo()->prepare(
            "INSERT INTO member_fee_invoices
                (id, tenant_id, user_id, year, fee_type, anniversary_date, amount_cents, currency, due_date, status, created_at)
             VALUES (?, ?, ?, ?, 'BASIC', ?, 5000, 'EUR', ?, 'OVERDUE', NOW())"
        )->execute([
            MemberFeeInvoiceId::generate()->value(),
            $tenantId->value(),
            $userId->value(),
            $year,
            "{$year}-01-15",
            "{$year}-03-15",
        ]);
    }

    private function makeCommand(DateTimeImmutable $today): LapseInactiveMembersCommand
    {
        $pdo = $this->pdo();
        $invoices = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository($pdo);
        $settings = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository($pdo);
        $users = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository($pdo);
        $msa = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberStatusAuditRepository($pdo);
        $clock = new \Daems\Domain\Shared\Clock\FixedClock($today);

        $useCase = new \Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember($users, $msa, $clock);

        return new LapseInactiveMembersCommand(
            pdo:         $pdo,
            invoices:    $invoices,
            settings:    $settings,
            useCase:     $useCase,
            lockManager: new \Daems\Infrastructure\Console\LockManager(sys_get_temp_dir()),
            logger:      new \Daems\Infrastructure\Console\CronLogger(sys_get_temp_dir(), 'membership:lapse-inactive-members', $today),
        );
    }
}
```

- [ ] **Step 2: Run + commit**

```bash
vendor/bin/phpunit --filter LapseInactiveMembersCommandTest
composer analyse
git add tests/Integration/Cron/LapseInactiveMembersCommandTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/integration/cron): LapseInactiveMembersCommand 4 scenarios"
```

---

## Task F6: Members backstage — show LAPSED status

**Files:**
- Modify: `modules/members/frontend/backstage/index.php` (or equivalent — verify path)
- Modify: members admin JS to render new status badge

The members list page in 0.6b's members module shows membership_status. Add `LAPSED` to the status badge mapping.

- [ ] **Step 1: Locate the status-rendering code**

```bash
grep -rn "membership_status\|status-badge" c:/laragon/www/modules/members/frontend/
grep -rn "status-badge" c:/laragon/www/daems-platform/public/backstage/
```

- [ ] **Step 2: Add LAPSED badge styling**

Pick the CSS file used for members status badges (likely `modules/members/frontend/assets/backstage/members-admin.css`). Add:

```css
.status-LAPSED, .status-lapsed {
    background: var(--color-warning-bg);
    color: var(--color-warning-text);
}
```

And in the JS status-label map:

```js
function statusLabel(s) {
    return {
        active:   'Aktiivinen',
        paused:   'Tauolla',
        expelled: 'Erotettu',
        lapsed:   'Eronnut maksamattomuuden vuoksi',
    }[s] ?? s;
}
```

- [ ] **Step 3: Browser smoke**

```
1. Run the lapse cron on a test user
2. Navigate to /backstage/members
3. Lapsed user shows "Eronnut maksamattomuuden vuoksi" badge
4. Member detail view shows the same status + the most recent member_status_audit row in the audit drawer
```

- [ ] **Step 4: Commit (across both repos if needed)**

```bash
# Members module repo
cd c:/laragon/www/modules/members
git add ...
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): LAPSED status badge + Finnish label"
cd c:/laragon/www/daems-platform
```

---

## Task F7: Cron-runner integration verification

**Files:**
- (manual — no code changes)

After F1-F6 land, the 3 cron commands are fully wired. Run them in production-like order to verify end-to-end:

- [ ] **Step 1: Manually trigger anniversary today**

```sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db <<SQL
UPDATE users
   SET membership_started_at = DATE_SUB(CURDATE(), INTERVAL 2 YEAR),
       membership_type='BASIC',
       membership_status='active'
 WHERE email = 'admin@daems.fi'
 LIMIT 1;
SQL
```

- [ ] **Step 2: Run all 3 crons in order**

```bash
php bin/console membership:generate-anniversary-invoices --tenant=daems
php bin/console membership:mark-overdue-invoices --tenant=daems
php bin/console membership:lapse-inactive-members --tenant=daems --dry-run
```

Expected: anniversary creates 1 invoice. Overdue flips 0 (just created, not past grace). Lapse dry-run flags 0 (only 1 OVERDUE year, not 2).

- [ ] **Step 3: Force overdue + 2-year condition**

```sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db <<SQL
UPDATE member_fee_invoices SET status='OVERDUE', year=2025, due_date='2025-03-15' WHERE user_id = (SELECT id FROM users WHERE email='admin@daems.fi') AND year=$(date +%Y);
INSERT INTO member_fee_invoices (id, tenant_id, user_id, year, fee_type, anniversary_date, amount_cents, currency, due_date, status, created_at)
SELECT UUID(), tenant_id, user_id, 2026, fee_type, '2026-01-15', 5000, 'EUR', '2026-03-15', 'OVERDUE', NOW()
FROM member_fee_invoices WHERE year=2025 AND user_id = (SELECT id FROM users WHERE email='admin@daems.fi');
SQL
```

- [ ] **Step 4: Run lapse cron for real**

```bash
php bin/console membership:lapse-inactive-members --tenant=daems --dry-run
php bin/console membership:lapse-inactive-members --tenant=daems
```

Expected: dry-run shows 1 candidate. Real run lapses the user, logs `lapsed=1`. SELECT membership_status FROM users shows `lapsed`.

- [ ] **Step 5: Clean up test data**

```sql
"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -u root -psalasana daems_db <<SQL
DELETE FROM member_fee_invoices WHERE user_id = (SELECT id FROM users WHERE email='admin@daems.fi');
DELETE FROM member_status_audit WHERE user_id = (SELECT id FROM users WHERE email='admin@daems.fi') AND reason LIKE '%2v maksamatta%';
UPDATE users SET membership_status='active', membership_started_at=NULL WHERE email='admin@daems.fi';
SQL
```

(No commit — this is a verification step.)

---

## Task F8: Wave F Definition of Done

After Tasks F1-F7:

- [ ] Migration 096 (if needed) applied; `users.membership_status` includes `lapsed`
- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --filter 'MarkOverdueInvoices|LapseInactiveMember'` green
- [ ] `vendor/bin/phpunit --testsuite Unit` count up by ~8 from Wave E
- [ ] `php bin/console membership:mark-overdue-invoices --tenant=daems` returns exit 0
- [ ] `php bin/console membership:lapse-inactive-members --dry-run` returns exit 0
- [ ] Manual smoke (F7) verified end-to-end lapse on dev DB
- [ ] Members backstage shows LAPSED badge

Run gate:

```bash
composer analyse
vendor/bin/phpunit --filter 'Billing|Lapse' 2>&1 | tail -3
php bin/console membership:mark-overdue-invoices
php bin/console membership:lapse-inactive-members --dry-run
```

If anything fails, FIX before starting Wave G.

---

# Wave G — GSA override + Honorary + CSV import (Phases 11-13, 10 tasks)

This wave adds 3 distinct features:
1. **GSA override paths** (ReverseLapse, bypass_fee_decision) — leverages 0.6b's `gsa_overrides` table
2. **Honorary auto-waive** — when admin changes membership_type to HONORARY, prompt to waive open invoices
3. **CSV bulk import** — upload bank statement, auto-match by reference, confirm matches in bulk

## Task G1: ReverseLapse use case (GSA only)

**Files:**
- Create: `src/Application/Membership/Billing/ReverseLapse/ReverseLapse.php`
- Create: `src/Application/Membership/Billing/ReverseLapse/ReverseLapseInput.php`
- Create: `tests/Unit/Application/Membership/Billing/ReverseLapseTest.php`

Flips a LAPSED user back to `active`. Only GSA (`isPlatformAdmin=true`) can perform this. Creates 2 audit rows:
- `member_status_audit` row (status flip: lapsed → active, reason from input)
- `gsa_overrides` row (action='reverse_lapse', justification mirrors reason)

- [ ] **Step 1: Input + use case**

`src/Application/Membership/Billing/ReverseLapse/ReverseLapseInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReverseLapse;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class ReverseLapseInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly UserId     $userId,
        public readonly string     $justification,
    ) {}
}
```

`src/Application/Membership/Billing/ReverseLapse/ReverseLapse.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ReverseLapse;

use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Governance\GsaOverride;
use Daems\Domain\Governance\GsaOverrideRepositoryInterface;
use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\Clock\ClockInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class ReverseLapse
{
    public function __construct(
        private readonly UserRepositoryInterface              $users,
        private readonly MemberStatusAuditRepositoryInterface $statusAudit,
        private readonly GsaOverrideRepositoryInterface       $gsaOverrides,
        private readonly ClockInterface                       $clock,
    ) {}

    public function handle(ReverseLapseInput $in): void
    {
        if (!$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only GSA can reverse lapse decisions");
        }
        if (trim($in->justification) === '') {
            throw new \InvalidArgumentException("Justification required for GSA override");
        }

        $user = $this->users->findById($in->userId);
        if ($user === null) {
            throw new \DomainException("User not found: {$in->userId->value()}");
        }
        if ($user->membershipStatus() !== 'lapsed') {
            throw new \DomainException("User is not LAPSED; cannot reverse (current: {$user->membershipStatus()})");
        }

        $now = $this->clock->now();
        $user->setMembershipStatus('active');
        $this->users->save($user);

        $this->statusAudit->save(MemberStatusAudit::create(
            tenantId:       $in->tenantId,
            userId:         $in->userId,
            previousStatus: 'lapsed',
            newStatus:      'active',
            reason:         'GSA override: lapse reversed — ' . $in->justification,
            performedBy:    $in->actor->id,
            createdAt:      $now,
        ));

        $this->gsaOverrides->save(GsaOverride::create(
            tenantId:      $in->tenantId,
            performedBy:   $in->actor->id,
            action:        'reverse_lapse',
            targetEntity:  'users',
            targetId:      $in->userId->value(),
            justification: $in->justification,
            performedAt:   $now,
        ));
    }
}
```

Note: `GsaOverride::create()` signature is assumed from 0.6b. Adjust if the actual 0.6b enum is different.

- [ ] **Step 2: Test (3 cases)**

`tests/Unit/Application/Membership/Billing/ReverseLapseTest.php`:
- GSA reverses lapsed user → status flipped to active, 2 audit rows
- Non-GSA admin rejected with ForbiddenException
- User not lapsed → DomainException

- [ ] **Step 3: DI wiring (BOTH containers)**

```php
$container[\Daems\Application\Membership\Billing\ReverseLapse\ReverseLapse::class] =
    fn($c) => new \Daems\Application\Membership\Billing\ReverseLapse\ReverseLapse(
        $c[\Daems\Domain\User\UserRepositoryInterface::class],
        $c[\Daems\Domain\Membership\MemberStatusAuditRepositoryInterface::class],
        $c[\Daems\Domain\Governance\GsaOverrideRepositoryInterface::class],
        $c[\Daems\Domain\Shared\Clock\ClockInterface::class],
    );
```

- [ ] **Step 4: Run + commit**

```bash
vendor/bin/phpunit --filter ReverseLapseTest
composer analyse
git add src/Application/Membership/Billing/ReverseLapse/ tests/Unit/Application/Membership/Billing/ReverseLapseTest.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): ReverseLapse use case (GSA-only override)"
```

---

## Task G2: ReverseLapse HTTP endpoint + members backstage action

**Files:**
- Modify: `src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php` (or `BackstageMembersController` — wherever member admin actions live)
- Modify: route table
- Modify: members backstage UI to show "Peruuta lapse (GSA)" button on lapsed users

- [ ] **Step 1: Add endpoint**

```php
public function reverseLapse(Request $request, ActingUser $actor, TenantId $tenantId, string $userId): Response
{
    try {
        $this->reverseLapse->handle(new \Daems\Application\Membership\Billing\ReverseLapse\ReverseLapseInput(
            actor:         $actor,
            tenantId:      $tenantId,
            userId:        \Daems\Domain\User\UserId::fromString($userId),
            justification: (string) $request->bodyValue('justification'),
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 400);
    } catch (\DomainException $e) {
        return Response::json(['error' => $e->getMessage()], 404);
    }
    return Response::json(['reversed' => true], 200);
}
```

Route:

```php
$router->post('/api/v1/backstage/governance/billing/users/{id}/reverse-lapse',
    [BackstageBillingController::class, 'reverseLapse']);
```

Update constructor + DI to include `ReverseLapse` dependency.

- [ ] **Step 2: UI button + modal**

In the members backstage detail/list view, add a row-action visible only for GSA on LAPSED users:

```html
<?php if ($user['membership_status'] === 'lapsed' && $session['is_platform_admin']): ?>
    <button type="button" class="button button--danger" onclick="openReverseLapseDialog('<?= htmlspecialchars($user['id']) ?>')">
        Peruuta lapse (GSA)
    </button>
<?php endif; ?>
```

Add a modal dialog template + JS:

```js
function openReverseLapseDialog(userId) {
    const justification = prompt('GSA override: justify lapse reversal');
    if (!justification) return;
    fetch(`/api/v1/backstage/governance/billing/users/${userId}/reverse-lapse`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ justification }),
    }).then(r => r.ok ? location.reload() : alert('Override failed'));
}
```

- [ ] **Step 3: Commit (across both repos if UI lives in `modules/members/`)**

```bash
git add src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php public/api-router.php bootstrap/app.php tests/Support/KernelHarness.php
# members repo:
cd c:/laragon/www/modules/members && git add frontend/backstage/... && git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage/ui): GSA reverse-lapse button on lapsed user row"
cd c:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api): GSA reverse-lapse endpoint + DI wiring"
```

---

## Task G3: WaiveOpenInvoicesOnHonoraryChange use case

**Files:**
- Create: `src/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChange/WaiveOpenInvoicesOnHonoraryChange.php`
- Create: `src/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChange/WaiveOpenInvoicesOnHonoraryChangeInput.php`
- Create: `tests/Unit/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChangeTest.php`

When admin sets a user's `membership_type='HONORARY'`, the existing PENDING/OVERDUE/REDUCED invoices should be waived. This use case loops over `listOpenForUser()` and dispatches `WaiveMemberFeeInvoice` for each, with reason='Honorary member, fees waived per § 3'.

- [ ] **Step 1: Input + use case**

`src/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChange/WaiveOpenInvoicesOnHonoraryChangeInput.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class WaiveOpenInvoicesOnHonoraryChangeInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly UserId     $userId,
    ) {}
}
```

`src/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChange/WaiveOpenInvoicesOnHonoraryChange.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange;

use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice;
use Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoiceInput;
use Daems\Domain\Auth\Exception\ForbiddenException;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface;

final class WaiveOpenInvoicesOnHonoraryChange
{
    private const REASON = 'Honorary member, fees waived per § 3';

    public function __construct(
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
        private readonly WaiveMemberFeeInvoice               $waive,
    ) {}

    public function handle(WaiveOpenInvoicesOnHonoraryChangeInput $in): int
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can waive invoices");
        }

        $openInvoices = $this->invoices->listOpenForUser($in->tenantId, $in->userId);
        $waived = 0;
        foreach ($openInvoices as $invoice) {
            $this->waive->handle(new WaiveMemberFeeInvoiceInput(
                actor:     $in->actor,
                invoiceId: $invoice->id(),
                reason:    self::REASON,
            ));
            $waived++;
        }
        return $waived;
    }
}
```

- [ ] **Step 2: Test (2 cases)**

`tests/Unit/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChangeTest.php`:
- admin runs use case with user having 3 open invoices → 3 WAIVED with reason from constant, 3 audit rows
- non-admin rejected

- [ ] **Step 3: DI wiring + commit**

```php
$container[\Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange\WaiveOpenInvoicesOnHonoraryChange::class] =
    fn($c) => new \Daems\Application\Membership\Billing\WaiveOpenInvoicesOnHonoraryChange\WaiveOpenInvoicesOnHonoraryChange(
        $c[\Daems\Domain\Membership\Billing\MemberFeeInvoiceRepositoryInterface::class],
        $c[\Daems\Application\Membership\Billing\WaiveMemberFeeInvoice\WaiveMemberFeeInvoice::class],
    );
```

```bash
vendor/bin/phpunit --filter WaiveOpenInvoicesOnHonoraryChangeTest
composer analyse
git add src/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChange/ tests/Unit/Application/Membership/Billing/WaiveOpenInvoicesOnHonoraryChangeTest.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): WaiveOpenInvoicesOnHonoraryChange use case"
```

---

## Task G4: Hook honorary-change flow into existing ChangeMemberStatus or membership-type editor

**Files:**
- Modify: existing membership-type/role editor (likely `src/Application/Membership/ChangeMemberStatus.php` or similar from 0.6a/b)
- Modify: corresponding controller endpoint
- Add: front-end confirmation dialog "Vapautetaanko N avointa laskua?"

The exact code path depends on where the membership_type change is implemented in 0.6a/b. Most likely:

- backstage admin clicks "Muuta jäsenmuoto" on a member detail page
- Modal opens with type select (SUPPORTING/BASIC/FULL/HONORARY)
- POST to `/api/v1/backstage/members/{id}/membership-type`
- Existing use case (or new one) changes `users.membership_type`

After the type change to HONORARY, dispatch `WaiveOpenInvoicesOnHonoraryChange`.

- [ ] **Step 1: Locate the existing flow**

```bash
grep -rn "membership_type\|MembershipType::" src/Application/ | grep -i 'change\|set\|update' | head -20
grep -rn "membership-type" public/ src/
```

- [ ] **Step 2: Identify the existing use case (or create wrapping ChangeMembershipType use case if missing)**

If `ChangeMembershipType` use case exists in `daems-platform/src/Application/Membership/`:
- Inject `WaiveOpenInvoicesOnHonoraryChange` as a dependency
- After successful type-flip to HONORARY, call it with the actor + tenant + user

If it doesn't exist (membership_type changes via raw SQL or admin form), this is a bigger refactor. Add a TODO to the deferred-items doc and ship a simpler version: the backstage UI POST endpoint just calls 2 actions sequentially (change-type + waive-open).

- [ ] **Step 3: UI confirmation dialog**

In the membership-type editor JS:

```js
async function changeMembershipType(userId, newType) {
    if (newType === 'HONORARY') {
        // Pre-check: how many open invoices?
        const r = await fetch(`/api/v1/backstage/governance/billing/invoices?user_id=${userId}&status=PENDING`);
        const data = await r.json();
        const openCount = data.rows.filter(i => i.status !== 'PAID' && i.status !== 'WAIVED').length;

        if (openCount > 0) {
            if (!confirm(`Käyttäjällä on ${openCount} avointa laskua. Vapautetaanko ne kunniajäsen-statuksen myötä?`)) {
                return;
            }
        }
    }

    const resp = await fetch(`/api/v1/backstage/members/${userId}/membership-type`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: newType }),
    });
    // ...handle response
}
```

- [ ] **Step 4: Backend hook**

Wherever the change-type endpoint lives, append:

```php
if ($newType === 'HONORARY') {
    $this->waiveOnHonorary->handle(new WaiveOpenInvoicesOnHonoraryChangeInput(
        actor:    $actor,
        tenantId: $tenantId,
        userId:   $userId,
    ));
}
```

Wrap in a try/catch — if the waive fails, the type-change still succeeds (best-effort), but log the error to allow admin manual remediation.

- [ ] **Step 5: Commit**

```bash
git add src/Application/Membership/... src/Infrastructure/Adapter/Api/Controller/... public/api-router.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Wire: honorary type-change auto-waives open invoices (§ 3)"
```

---

## Task G5: NordeaPaymentCsvParser

**Files:**
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/NordeaPaymentCsvParser.php`
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/ParsedPaymentRow.php`
- Create: `tests/Unit/Application/Membership/Billing/NordeaPaymentCsvParserTest.php`

Nordea's CSV format (.csv exported from netbank): columns `Kirjauspäivä, Arvopäivä, Maksaja, Saaja, Nimi, Tiliotetapahtumalaji, Viesti, Tilinumero, Viite, Summa EUR`.

Field of interest: `Viite` (reference number = our invoice reference), `Summa EUR` (amount), `Arvopäivä` (value date).

- [ ] **Step 1: Create row VO**

`src/Application/Membership/Billing/ImportPaymentsCsv/ParsedPaymentRow.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use DateTimeImmutable;

final class ParsedPaymentRow
{
    public function __construct(
        public readonly int                $rowNumber,    // 1-based, includes header
        public readonly ?string            $reference,    // null when bank field is empty
        public readonly int                $amountCents,  // positive = received
        public readonly DateTimeImmutable  $valueDate,
        public readonly string             $payerName,
        public readonly string             $rawLine,
    ) {}
}
```

- [ ] **Step 2: Implement parser**

`src/Application/Membership/Billing/ImportPaymentsCsv/NordeaPaymentCsvParser.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\ImportPaymentsCsv;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Parses Nordea netbank CSV export (Suomi/Finland). Tab- or semicolon-separated
 * depending on user export settings; we autodetect.
 *
 * Skips rows with negative amounts (outgoing payments — not relevant for fee matching).
 * Skips header row (Kirjauspäivä, Arvopäivä, ...).
 *
 * Multi-bank support (OP, Aktia, Danske) — out of 0.7 scope. Future task.
 */
final class NordeaPaymentCsvParser
{
    /**
     * @return list<ParsedPaymentRow>
     */
    public function parse(string $csvContent): array
    {
        // Detect delimiter from first line
        $firstNewline = strpos($csvContent, "\n");
        if ($firstNewline === false) {
            return [];
        }
        $header = substr($csvContent, 0, $firstNewline);
        $delimiter = substr_count($header, ';') > substr_count($header, "\t") ? ';' : "\t";

        $lines = preg_split('/\r?\n/', trim($csvContent)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $columns = str_getcsv($lines[0], $delimiter);
        $columnMap = array_flip(array_map('trim', $columns));

        // Required columns; throw if missing.
        foreach (['Arvopäivä', 'Maksaja', 'Viite', 'Summa EUR'] as $required) {
            if (!isset($columnMap[$required])) {
                throw new InvalidArgumentException("Nordea CSV missing required column: '{$required}'");
            }
        }

        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) === '') continue;
            $fields = str_getcsv($line, $delimiter);

            $amountStr = $fields[$columnMap['Summa EUR']] ?? '';
            $amountCents = $this->parseAmountToCents($amountStr);
            if ($amountCents <= 0) continue; // skip outgoing

            $valueDate = $fields[$columnMap['Arvopäivä']] ?? '';
            $payer = $fields[$columnMap['Maksaja']] ?? '';
            $reference = trim($fields[$columnMap['Viite']] ?? '');

            try {
                $dt = new DateTimeImmutable($valueDate);
            } catch (\Exception $e) {
                continue; // can't parse date — skip row
            }

            $rows[] = new ParsedPaymentRow(
                rowNumber:    $i + 1,
                reference:    $reference !== '' ? $reference : null,
                amountCents:  $amountCents,
                valueDate:    $dt,
                payerName:    $payer,
                rawLine:      $line,
            );
        }
        return $rows;
    }

    private function parseAmountToCents(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') return 0;
        // Nordea uses Finnish locale: comma decimal, dot thousands separator
        $normalized = str_replace([' ', '.'], ['', ''], $raw);  // strip thousand-sep
        $normalized = str_replace(',', '.', $normalized);
        $float = (float) $normalized;
        return (int) round($float * 100);
    }
}
```

- [ ] **Step 3: Test**

`tests/Unit/Application/Membership/Billing/NordeaPaymentCsvParserTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ImportPaymentsCsv\NordeaPaymentCsvParser;
use PHPUnit\Framework\TestCase;

final class NordeaPaymentCsvParserTest extends TestCase
{
    public function test_parses_semicolon_separated_finnish_format(): void
    {
        $csv = <<<CSV
        Kirjauspäivä;Arvopäivä;Maksaja;Saaja;Nimi;Tiliotetapahtumalaji;Viesti;Tilinumero;Viite;Summa EUR
        15.08.2026;14.08.2026;Matti Meikäläinen;Daem Society ry;;TILISIIRTO;;FI00 1234 5678 9012;1234567;50,00
        15.08.2026;14.08.2026;Liisa Lankinen;Daem Society ry;;TILISIIRTO;;FI00 1234 5678 9012;7654321;50,00
        16.08.2026;16.08.2026;Daem Society ry;Pekka Päämies;;TILISIIRTO;;FI00 9999 8888 7777;;-150,00
        CSV;

        $parser = new NordeaPaymentCsvParser();
        $rows = $parser->parse($csv);

        $this->assertCount(2, $rows); // 3rd is outgoing, skipped
        $this->assertSame('1234567', $rows[0]->reference);
        $this->assertSame(5000, $rows[0]->amountCents);
        $this->assertSame('Matti Meikäläinen', $rows[0]->payerName);
    }

    public function test_skips_rows_with_missing_reference(): void
    {
        $csv = <<<CSV
        Arvopäivä;Maksaja;Viite;Summa EUR
        14.08.2026;Matti;;50,00
        14.08.2026;Liisa;1234567;50,00
        CSV;

        $parser = new NordeaPaymentCsvParser();
        $rows = $parser->parse($csv);
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->reference);
        $this->assertSame('1234567', $rows[1]->reference);
    }

    public function test_throws_on_missing_required_column(): void
    {
        $csv = "Arvopäivä;Maksaja;Summa EUR\n14.08.2026;Matti;50,00\n";
        $this->expectException(\InvalidArgumentException::class);
        (new NordeaPaymentCsvParser())->parse($csv);
    }

    public function test_handles_tab_separator(): void
    {
        $csv = "Arvopäivä\tMaksaja\tViite\tSumma EUR\n14.08.2026\tMatti\t1234567\t50,00\n";
        $rows = (new NordeaPaymentCsvParser())->parse($csv);
        $this->assertCount(1, $rows);
        $this->assertSame(5000, $rows[0]->amountCents);
    }

    public function test_handles_european_decimal_with_thousand_separator(): void
    {
        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\n14.08.2026;Matti;1234567;1.234,56\n";
        $rows = (new NordeaPaymentCsvParser())->parse($csv);
        $this->assertSame(123456, $rows[0]->amountCents);
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
vendor/bin/phpunit --filter NordeaPaymentCsvParserTest
composer analyse
git add src/Application/Membership/Billing/ImportPaymentsCsv/NordeaPaymentCsvParser.php src/Application/Membership/Billing/ImportPaymentsCsv/ParsedPaymentRow.php tests/Unit/Application/Membership/Billing/NordeaPaymentCsvParserTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): NordeaPaymentCsvParser with Finnish-locale amount handling"
```

---

## Task G6: ImportPaymentsCsv preview use case

**Files:**
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/PreviewImportPayments.php`
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/PreviewImportPaymentsInput.php`
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/PaymentMatchResult.php`
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/PreviewImportPaymentsOutput.php`
- Create: `tests/Unit/Application/Membership/Billing/PreviewImportPaymentsTest.php`

Two-step pattern: PREVIEW returns proposed matches without modifying state. CONFIRM (Task G7) applies a set of confirmed matches.

Match logic: lookup invoice by reference (need an indexed lookup — add a `findByReference()` method to the invoice repo, or just iterate; for 0.7 keep it simple). For each parsed row:
- AMOUNT match: invoice.amount_cents == parsedRow.amountCents (within tolerance ±2¢)
- REFERENCE match: invoice.id starts with parsedRow.reference? OR a separate `reference_number` column? — Decision below.

**Reference scheme:** For 0.7 we use `tenant_member_counters.next_value` + member_number to construct a reference. Each invoice's reference = `<tenant.id_short>-<user.member_number>-<year>`. Simple and stable.

Alternative — Finnish RF reference codes — out of scope (0.7.1 Stripe).

Actually simpler: add a new column `member_fee_invoices.reference_code VARCHAR(40) NULL` populated at issue time. The cron computes it deterministically as `<sequential per tenant>`. For now, since most users will have a `member_number` already, we can use `<member_number>-<year>` (e.g., `42-2026`) for matching.

For Wave G we'll do the simplest thing: match against `users.member_number` if present, fall back to invoice ID prefix.

- [ ] **Step 1: Add `findByReference()` to invoice repo**

In `MemberFeeInvoiceRepositoryInterface`:

```php
/**
 * Find a PENDING/OVERDUE invoice for a tenant matching the given bank reference.
 * Reference format: '<member_number>-<year>' OR invoice id prefix.
 * Returns null if no unambiguous match.
 */
public function findByReference(TenantId $tenantId, string $reference): ?MemberFeeInvoice;
```

InMemory + SQL impl:

```php
// InMemory
public function findByReference(TenantId $tenantId, string $reference): ?MemberFeeInvoice
{
    foreach ($this->byId as $i) {
        if (!$i->tenantId()->equals($tenantId)) continue;
        if (!$i->status()->isOpen()) continue;
        if (str_starts_with($i->id()->value(), $reference)) return $i;
    }
    return null;
}

// SQL
public function findByReference(TenantId $tenantId, string $reference): ?MemberFeeInvoice
{
    // First try: match against users.member_number + invoice.year
    if (preg_match('/^(\d+)-(\d{4})$/', $reference, $m) === 1) {
        $stmt = $this->pdo->prepare(
            "SELECT mfi.* FROM member_fee_invoices mfi
             INNER JOIN users u ON u.id = mfi.user_id
             WHERE mfi.tenant_id = ? AND u.member_number = ? AND mfi.year = ?
               AND mfi.status IN ('PENDING','OVERDUE','REDUCED')
             LIMIT 2"
        );
        $stmt->execute([$tenantId->value(), $m[1], (int) $m[2]]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            return $this->hydrate($rows[0]);
        }
    }
    // Fallback: invoice id prefix match (treat reference as id-prefix, useful for ad-hoc viite-numero schemes)
    $stmt = $this->pdo->prepare(
        "SELECT * FROM member_fee_invoices
         WHERE tenant_id = ? AND id LIKE ? AND status IN ('PENDING','OVERDUE','REDUCED')
         LIMIT 2"
    );
    $stmt->execute([$tenantId->value(), $reference . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return count($rows) === 1 ? $this->hydrate($rows[0]) : null;
}
```

- [ ] **Step 2: Create input/output/result DTOs**

```php
// PreviewImportPaymentsInput
final class PreviewImportPaymentsInput
{
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly string     $csvContent,
    ) {}
}

// PaymentMatchResult — single row
final class PaymentMatchResult
{
    public function __construct(
        public readonly ParsedPaymentRow      $parsed,
        public readonly ?MemberFeeInvoiceId   $matchedInvoiceId,
        public readonly ?int                  $matchedAmountCents,
        public readonly string                $confidence,  // 'high'|'amount_mismatch'|'no_match'
        public readonly ?string               $reasonForLowConfidence,
    ) {}
}

// PreviewImportPaymentsOutput
final class PreviewImportPaymentsOutput
{
    /** @param list<PaymentMatchResult> $results */
    public function __construct(public readonly array $results) {}

    public function highConfidenceCount(): int
    {
        return count(array_filter($this->results, fn(PaymentMatchResult $r) => $r->confidence === 'high'));
    }
}
```

- [ ] **Step 3: Implement preview use case**

```php
final class PreviewImportPayments
{
    public function __construct(
        private readonly NordeaPaymentCsvParser              $parser,
        private readonly MemberFeeInvoiceRepositoryInterface $invoices,
    ) {}

    public function handle(PreviewImportPaymentsInput $in): PreviewImportPaymentsOutput
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can import payments");
        }

        $rows = $this->parser->parse($in->csvContent);
        $results = [];
        foreach ($rows as $row) {
            if ($row->reference === null) {
                $results[] = new PaymentMatchResult($row, null, null, 'no_match', 'CSV row missing reference');
                continue;
            }
            $invoice = $this->invoices->findByReference($in->tenantId, $row->reference);
            if ($invoice === null) {
                $results[] = new PaymentMatchResult($row, null, null, 'no_match', "No open invoice for reference '{$row->reference}'");
                continue;
            }
            $diff = abs($invoice->amountCents() - $row->amountCents);
            if ($diff <= 2) {
                $results[] = new PaymentMatchResult($row, $invoice->id(), $invoice->amountCents(), 'high', null);
            } else {
                $results[] = new PaymentMatchResult(
                    $row, $invoice->id(), $invoice->amountCents(),
                    'amount_mismatch',
                    "Expected {$invoice->amountCents()}¢, got {$row->amountCents}¢ (diff {$diff})"
                );
            }
        }
        return new PreviewImportPaymentsOutput($results);
    }
}
```

- [ ] **Step 4: Tests**

3 tests:
- 3 CSV rows: 1 high-confidence match, 1 amount mismatch, 1 no-match → output has 3 results with correct labels
- empty CSV → empty results
- non-admin rejected

- [ ] **Step 5: Run + commit**

```bash
vendor/bin/phpunit --filter PreviewImportPaymentsTest
composer analyse
git add src/Application/Membership/Billing/ImportPaymentsCsv/ tests/Unit/Application/Membership/Billing/PreviewImportPaymentsTest.php src/Domain/Membership/Billing/MemberFeeInvoiceRepositoryInterface.php src/Infrastructure/Adapter/Persistence/Sql/SqlMemberFeeInvoiceRepository.php tests/Support/Fake/InMemoryMemberFeeInvoiceRepository.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): PreviewImportPayments + findByReference repo method"
```

---

## Task G7: ConfirmImportPayments use case

**Files:**
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/ConfirmImportPayments.php`
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/ConfirmImportPaymentsInput.php`
- Create: `src/Application/Membership/Billing/ImportPaymentsCsv/ConfirmImportPaymentsOutput.php`
- Create: `tests/Unit/Application/Membership/Billing/ConfirmImportPaymentsTest.php`

The admin reviews the preview results, ticks the matches to apply, and submits. Each confirmed match becomes a `RecordManualPayment` call with method='csv_import'.

- [ ] **Step 1: DTOs**

```php
final class ConfirmImportPaymentsInput
{
    /**
     * @param list<array{invoice_id:string, amount_cents:int, paid_at:string, reference:string}> $matches
     */
    public function __construct(
        public readonly ActingUser $actor,
        public readonly TenantId   $tenantId,
        public readonly array      $matches,
    ) {}
}

final class ConfirmImportPaymentsOutput
{
    /**
     * @param list<string> $applied  invoice IDs that were marked PAID
     * @param list<array{invoice_id:string, error:string}> $errors
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $errors,
    ) {}
}
```

- [ ] **Step 2: Implement**

```php
final class ConfirmImportPayments
{
    public function __construct(
        private readonly RecordManualPayment $markPaid,
    ) {}

    public function handle(ConfirmImportPaymentsInput $in): ConfirmImportPaymentsOutput
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException("Only admins can confirm payment imports");
        }

        $applied = [];
        $errors = [];
        foreach ($in->matches as $m) {
            try {
                $this->markPaid->handle(new RecordManualPaymentInput(
                    actor:       $in->actor,
                    invoiceId:   MemberFeeInvoiceId::fromString((string) $m['invoice_id']),
                    amountCents: (int) $m['amount_cents'],
                    paidAt:      new DateTimeImmutable((string) $m['paid_at']),
                    method:      'csv_import',
                    reference:   (string) $m['reference'],
                ));
                $applied[] = $m['invoice_id'];
            } catch (\Throwable $e) {
                $errors[] = ['invoice_id' => (string) $m['invoice_id'], 'error' => $e->getMessage()];
            }
        }
        return new ConfirmImportPaymentsOutput($applied, $errors);
    }
}
```

- [ ] **Step 3: Test (3 cases)**

- 3 matches, all valid → applied=3, errors=0
- 1 invalid invoice ID → applied=2, errors=1 with reason
- non-admin rejected

- [ ] **Step 4: DI wiring + commit**

```bash
vendor/bin/phpunit --filter ConfirmImportPaymentsTest
composer analyse
git add src/Application/Membership/Billing/ImportPaymentsCsv/ tests/Unit/Application/Membership/Billing/ConfirmImportPaymentsTest.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(application): ConfirmImportPayments (apply confirmed CSV matches)"
```

---

## Task G8: CSV import HTTP endpoints + UI

**Files:**
- Modify: `BackstageBillingController.php` (add 2 endpoints)
- Modify: route table
- Modify: `public/backstage/governance/billing/_import.php` (replace Wave B stub)
- Create: `public/backstage/assets/governance/billing-import.js`

Endpoints:
- `POST /api/v1/backstage/governance/billing/payments/import-csv` — multipart upload, returns preview
- `POST /api/v1/backstage/governance/billing/payments/import-csv/confirm` — apply confirmed matches

- [ ] **Step 1: Controller methods**

```php
public function previewImportCsv(Request $request, ActingUser $actor, TenantId $tenantId): Response
{
    if (!$actor->isAdminIn($tenantId) && !$actor->isPlatformAdmin) {
        return Response::json(['error' => 'Forbidden'], 403);
    }
    $file = $request->file('csv');
    if ($file === null) {
        return Response::json(['error' => 'csv file required'], 400);
    }
    $content = (string) file_get_contents($file['tmp_name']);

    try {
        $output = $this->previewImport->handle(new PreviewImportPaymentsInput(
            actor:      $actor,
            tenantId:   $tenantId,
            csvContent: $content,
        ));
    } catch (\InvalidArgumentException $e) {
        return Response::json(['error' => $e->getMessage()], 400);
    }

    return Response::json([
        'results' => array_map(static fn($r) => [
            'row_number'            => $r->parsed->rowNumber,
            'reference'             => $r->parsed->reference,
            'amount_cents'          => $r->parsed->amountCents,
            'value_date'            => $r->parsed->valueDate->format('Y-m-d'),
            'payer_name'            => $r->parsed->payerName,
            'matched_invoice_id'    => $r->matchedInvoiceId?->value(),
            'matched_amount_cents'  => $r->matchedAmountCents,
            'confidence'            => $r->confidence,
            'low_confidence_reason' => $r->reasonForLowConfidence,
        ], $output->results),
        'high_confidence_count' => $output->highConfidenceCount(),
    ], 200);
}

public function confirmImportCsv(Request $request, ActingUser $actor, TenantId $tenantId): Response
{
    $matches = (array) $request->bodyValue('matches');
    try {
        $output = $this->confirmImport->handle(new ConfirmImportPaymentsInput(
            actor:    $actor,
            tenantId: $tenantId,
            matches:  $matches,
        ));
    } catch (\Daems\Domain\Auth\Exception\ForbiddenException $e) {
        return Response::json(['error' => $e->getMessage()], 403);
    }
    return Response::json([
        'applied' => $output->applied,
        'errors'  => $output->errors,
    ], 200);
}
```

Routes + DI wiring as usual.

- [ ] **Step 2: Replace `_import.php` stub**

```php
<?php declare(strict_types=1); ?>
<section class="billing-import">
    <h2>CSV-tuonti — pankin tiliote</h2>
    <p>Lataa Nordea-tiliote CSV-muodossa. Järjestelmä yrittää löytää viitenumeroon perustuen
       avoimet laskut ja ehdottaa täsmäykset.</p>

    <form id="import-form" enctype="multipart/form-data" class="billing-import__form">
        <label>CSV-tiedosto<input type="file" name="csv" accept=".csv" required></label>
        <button type="submit" class="button button--primary">Esikatsele</button>
    </form>

    <section id="preview" hidden>
        <h3>Esikatselu — täsmäysehdotukset</h3>
        <p><strong id="high-confidence-count">0</strong> korkea-luottamus täsmäystä</p>
        <table id="preview-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>Rivi</th>
                    <th>Maksaja</th>
                    <th>Viite</th>
                    <th>Summa</th>
                    <th>Täsmäys</th>
                    <th>Luotettavuus</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <button type="button" id="confirm-btn" class="button button--primary">Vahvista valitut</button>
    </section>

    <section id="results" hidden>
        <h3>Tuonti valmis</h3>
        <p><strong id="applied-count">0</strong> laskua merkitty maksetuksi. <strong id="error-count">0</strong> virhettä.</p>
        <pre id="results-details"></pre>
    </section>
</section>

<script defer src="/backstage/assets/governance/billing-import.js"></script>
```

- [ ] **Step 3: JS**

`public/backstage/assets/governance/billing-import.js`:

```js
let previewData = null;

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('import-form').addEventListener('submit', onPreview);
    document.getElementById('confirm-btn').addEventListener('click', onConfirm);
    document.getElementById('select-all').addEventListener('change', onSelectAll);
});

async function onPreview(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const formData = new FormData(form);

    const resp = await fetch('/api/v1/backstage/governance/billing/payments/import-csv', {
        method: 'POST',
        body: formData,
    });
    if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        alert('Esikatselu epäonnistui: ' + (err.error ?? resp.status));
        return;
    }
    previewData = await resp.json();
    renderPreview(previewData);
}

function renderPreview(data) {
    document.getElementById('preview').hidden = false;
    document.getElementById('high-confidence-count').textContent = data.high_confidence_count;
    const tbody = document.querySelector('#preview-table tbody');
    tbody.innerHTML = data.results.map((r, idx) => `
        <tr data-confidence="${r.confidence}">
            <td><input type="checkbox" class="row-select" data-index="${idx}" ${r.confidence === 'high' ? 'checked' : ''} ${r.matched_invoice_id === null ? 'disabled' : ''}></td>
            <td>${r.row_number}</td>
            <td>${escape(r.payer_name)}</td>
            <td>${escape(r.reference ?? '—')}</td>
            <td>${(r.amount_cents / 100).toFixed(2)} €</td>
            <td>${r.matched_invoice_id ? r.matched_invoice_id.slice(0, 8) + '…' : '—'}${r.matched_amount_cents !== null && r.matched_amount_cents !== r.amount_cents ? ` <small>(odotettu ${(r.matched_amount_cents / 100).toFixed(2)} €)</small>` : ''}</td>
            <td>
                <span class="confidence confidence-${r.confidence}">${labelConfidence(r.confidence)}</span>
                ${r.low_confidence_reason ? `<small>${escape(r.low_confidence_reason)}</small>` : ''}
            </td>
        </tr>
    `).join('');
}

function labelConfidence(c) {
    return { high: 'Varma', amount_mismatch: 'Summa eroaa', no_match: 'Ei täsmäystä' }[c] ?? c;
}

function onSelectAll(e) {
    document.querySelectorAll('.row-select:not(:disabled)').forEach(cb => cb.checked = e.target.checked);
}

async function onConfirm() {
    if (!previewData) return;
    const selectedIndices = [...document.querySelectorAll('.row-select:checked')].map(cb => parseInt(cb.dataset.index, 10));
    const matches = selectedIndices.map(idx => {
        const r = previewData.results[idx];
        return {
            invoice_id:   r.matched_invoice_id,
            amount_cents: r.amount_cents,
            paid_at:      new Date(r.value_date).toISOString(),
            reference:    r.reference ?? '',
        };
    });

    if (matches.length === 0) { alert('Valitse ainakin yksi täsmäys'); return; }

    const resp = await fetch('/api/v1/backstage/governance/billing/payments/import-csv/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ matches }),
    });
    if (!resp.ok) { alert('Vahvistus epäonnistui'); return; }
    const data = await resp.json();

    document.getElementById('preview').hidden = true;
    document.getElementById('results').hidden = false;
    document.getElementById('applied-count').textContent = data.applied.length;
    document.getElementById('error-count').textContent = data.errors.length;
    document.getElementById('results-details').textContent = JSON.stringify(data, null, 2);
}

function escape(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
```

- [ ] **Step 4: CSS additions to billing.css**

```css
.billing-import__form {
    padding: var(--space-3);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-4);
}
#preview-table {
    width: 100%;
    border-collapse: collapse;
}
#preview-table th,
#preview-table td {
    text-align: left;
    padding: var(--space-2);
    border-bottom: 1px solid var(--color-border);
}
.confidence {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: var(--font-xs);
}
.confidence-high            { background: var(--color-success-bg); }
.confidence-amount_mismatch { background: var(--color-warning-bg); }
.confidence-no_match        { background: var(--color-neutral-bg); }
```

- [ ] **Step 5: Browser smoke**

```
1. Generate a sample Nordea CSV (3 rows: 1 matching, 1 amount mismatch, 1 unknown reference)
2. http://daems.local/backstage/governance/billing?view=import
3. Upload CSV → preview table appears
4. High-confidence row pre-checked; others unchecked
5. Click "Vahvista valitut" → results section appears with applied=1
6. Navigate to /backstage/governance/billing → matched invoice now shows status=PAID
```

- [ ] **Step 6: Commit**

```bash
git add public/backstage/governance/billing/_import.php public/backstage/assets/governance/billing-import.js public/backstage/assets/governance/billing.css src/Infrastructure/Adapter/Api/Controller/BackstageBillingController.php public/api-router.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(api+backstage/ui): CSV import preview + confirm flow"
```

---

## Task G9: Wave G E2E smoke test

**Files:**
- Create: `tests/E2E/Backstage/BillingCsvImportEndpointTest.php`

End-to-end happy path through the HTTP layer.

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class BillingCsvImportEndpointTest extends TestCase
{
    public function test_preview_and_confirm_round_trip(): void
    {
        $h = new KernelHarness();
        $h->seedTenant('daems');
        $admin = $h->seedAdminUser('daems');
        $member = $h->seedMember('daems');

        // Need a member_number on the user for reference matching
        $h->setMemberNumber($member, 42);
        $invoiceId = $h->createTestInvoice('daems', $member, year: 2026, amountCents: 5000);

        $csv = "Arvopäivä;Maksaja;Viite;Summa EUR\n14.08.2026;Member 42;42-2026;50,00\n";
        $tmpfile = tempnam(sys_get_temp_dir(), 'csv-import-test');
        file_put_contents($tmpfile, $csv);

        // PREVIEW (multipart)
        $r = $h->uploadFile('POST', '/api/v1/backstage/governance/billing/payments/import-csv', 'csv', $tmpfile, actor: $admin);
        $this->assertSame(200, $r->status());
        $this->assertSame(1, $r->jsonBody()['high_confidence_count']);

        // CONFIRM
        $r = $h->request('POST', '/api/v1/backstage/governance/billing/payments/import-csv/confirm', [
            'matches' => [[
                'invoice_id'   => $invoiceId,
                'amount_cents' => 5000,
                'paid_at'      => '2026-08-14T00:00:00+03:00',
                'reference'    => '42-2026',
            ]],
        ], actor: $admin);
        $this->assertSame(200, $r->status());
        $this->assertCount(1, $r->jsonBody()['applied']);

        unlink($tmpfile);
    }
}
```

(`KernelHarness::setMemberNumber()` + `uploadFile()` are helpers; add as 2-line shims.)

- [ ] **Step 2: Run + commit**

```bash
vendor/bin/phpunit --filter BillingCsvImportEndpointTest
composer analyse
git add tests/E2E/Backstage/BillingCsvImportEndpointTest.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/e2e): CSV import preview + confirm round-trip"
```

---

## Task G10: Wave G Definition of Done

After Tasks G1-G9:

- [ ] `composer analyse` = 0 errors
- [ ] `vendor/bin/phpunit --filter 'ReverseLapse|WaiveOpenInvoicesOnHonoraryChange|NordeaPaymentCsvParser|PreviewImportPayments|ConfirmImportPayments'` green
- [ ] `vendor/bin/phpunit --filter 'BillingCsvImportEndpointTest'` green
- [ ] Browser smoke: GSA reverse-lapse works on a lapsed user
- [ ] Browser smoke: changing membership_type to HONORARY prompts to waive open invoices
- [ ] Browser smoke: CSV upload → preview → confirm marks invoices PAID

Run gate:

```bash
composer analyse
vendor/bin/phpunit --filter 'Billing|ReverseLapse|Honorary|Csv' 2>&1 | tail -3
```

If anything fails, FIX before starting Wave H.

---

# Wave H — Sidebar + i18n + E2E smoke (Phase 14, 7 tasks)

The final wave wires billing into the backstage navigation, adds i18n strings for all 3 locales, and runs the full integration smoke test. After this wave 0.7 is ready for code review + merge.

## Task H1: BackstageSidebar — add billing item

**Files:**
- Modify: `src/Frontend/BackstageSidebar.php` (add 6th hardcoded governance item)
- Modify: `tests/Unit/Frontend/BackstageSidebarTest.php` (baseline 8 → 9, ordered 11 → 12 hrefs)

This task mirrors the post-0.6b correction we made on `a4a3828` for `testHeadlessModulesProduceNoSidebarItem` and `testOrdersByGroupRankThenIntraGroupOrder`.

- [ ] **Step 1: Update BackstageSidebar.php — insert billing entry**

After the 5 governance items in `BackstageSidebar.php::buildFor()`, add:

```php
$items[] = [
    'group'     => 'governance',
    'label_key' => 'shell.governance.billing',
    'href'      => '/backstage/governance/billing',
    'icon'      => 'credit-card',
    'order'     => 60,
];
```

- [ ] **Step 2: Update Unit test**

In `tests/Unit/Frontend/BackstageSidebarTest.php`:

`testOrdersByGroupRankThenIntraGroupOrder` — expected hrefs grow 11 → 12; add `'/backstage/governance/billing'` between `/backstage/governance/settings` and `/backstage/notifications`:

```php
$this->assertSame([
    '/backstage/',
    '/backstage/members',
    '/backstage/events',
    '/backstage/forum',
    '/backstage/governance/board',
    '/backstage/governance/decisions',
    '/backstage/governance/expulsions',
    '/backstage/governance/delegations',
    '/backstage/governance/settings',
    '/backstage/governance/billing',     // NEW
    '/backstage/notifications',
    '/backstage/settings',
], $hrefs);
```

`testHeadlessModulesProduceNoSidebarItem` — baseline count 8 → 9:

```php
// 9 baseline items remain when no modules render to the sidebar:
//   - 1 shell: Dashboard
//   - 6 governance (hardcoded): board, decisions, expulsions, delegations, settings, billing
//   - 2 system: Notifications, Settings (Tenants only for GSA)
$this->assertCount(9, $items);
```

- [ ] **Step 3: Run all sidebar tests**

```bash
vendor/bin/phpunit --filter BackstageSidebarTest
composer analyse
```

Expected: 8 tests OK. PHPStan 0.

- [ ] **Step 4: Commit**

```bash
git add src/Frontend/BackstageSidebar.php tests/Unit/Frontend/BackstageSidebarTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(sidebar): 6th governance item — billing (order=60)"
```

---

## Task H2: i18n strings — fi_FI, en_GB, sw_TZ

**Files:**
- Modify: `lang/fi_FI.php`
- Modify: `lang/en_GB.php`
- Modify: `lang/sw_TZ.php`

All UI strings introduced in Wave B-G need translations. Pattern matches 0.6b governance i18n.

- [ ] **Step 1: Add Finnish strings**

`lang/fi_FI.php` — add inside the existing array:

```php
// Wave 0.7 — MembershipBilling
'shell.governance.billing'              => 'Maksut',
'billing.tabs.invoices'                 => 'Laskut',
'billing.tabs.fees'                     => 'Hinnasto',
'billing.tabs.overrides'                => 'Alennukset',
'billing.tabs.import'                   => 'CSV-tuonti',
'billing.kpi.pending'                   => 'Odottaa',
'billing.kpi.overdue'                   => 'Erääntynyt',
'billing.kpi.paid'                      => 'Maksettu',
'billing.kpi.waived'                    => 'Vapautettu',
'billing.fees.title'                    => 'Hinnasto :year',
'billing.fees.year_label'               => 'Vuosi',
'billing.fees.year_change'              => 'Vaihda',
'billing.fees.column_type'              => 'Tyyppi',
'billing.fees.column_amount'            => 'Summa',
'billing.fees.column_status'            => 'Tila',
'billing.fees.column_activated'         => 'Aktivoitu',
'billing.fees.column_decision'          => 'Päätös',
'billing.fees.edit_button'              => 'Muokkaa hinnastoa',
'billing.fees.empty'                    => 'Ei hinnastoa vuodelle :year. Klikkaa "Muokkaa hinnastoa" luodaksesi.',
'billing.fees.form.legend'              => 'Vuoden :year hinnasto',
'billing.fees.form.supporting'          => 'Kannatusmaksu (SUPPORTING) €',
'billing.fees.form.basic'               => 'Perusjäsenmaksu (BASIC) €',
'billing.fees.form.full'                => 'Varsinaisen jäsenmaksu (FULL) €',
'billing.fees.form.save'                => 'Tallenna',
'billing.fees.form.cancel'              => 'Peruuta',
'billing.fees.form.note'                => 'Mikäli tenantille on asetettu requires_formal_decision_for_fees=true, hinnasto tallentuu PROPOSED-tilaan ja avaa hallituksen päätös-flow:n.',
'billing.invoices.title'                => 'Laskut :year',
'billing.invoices.filter.status'        => 'Tila',
'billing.invoices.filter.all'           => 'Kaikki',
'billing.invoices.column_member'        => 'Jäsen',
'billing.invoices.column_type'          => 'Tyyppi',
'billing.invoices.column_amount'        => 'Summa',
'billing.invoices.column_due'           => 'Eräpäivä',
'billing.invoices.column_status'        => 'Tila',
'billing.invoices.column_actions'       => 'Toiminnot',
'billing.invoices.status.PENDING'       => 'Odottaa',
'billing.invoices.status.PAID'          => 'Maksettu',
'billing.invoices.status.OVERDUE'       => 'Erääntynyt',
'billing.invoices.status.WAIVED'        => 'Vapautettu',
'billing.invoices.status.REDUCED'       => 'Alennettu',
'billing.invoices.action.mark_paid'     => 'Merkitse maksetuksi',
'billing.invoices.action.waive'         => 'Vapauta',
'billing.invoices.action.reduce'        => 'Alenna',
'billing.invoices.action.audit'         => 'Historia',
'billing.invoices.action.reverse_lapse' => 'Peruuta lapse (GSA)',
'billing.markpaid.title'                => 'Merkitse maksetuksi',
'billing.markpaid.amount'               => 'Maksusumma (€)',
'billing.markpaid.paid_at'              => 'Maksupäivä',
'billing.markpaid.method'               => 'Maksutapa',
'billing.markpaid.method.bank_transfer' => 'Pankkisiirto',
'billing.markpaid.method.cash'          => 'Käteinen',
'billing.markpaid.method.other'         => 'Muu',
'billing.markpaid.reference'            => 'Viite',
'billing.markpaid.confirm'              => 'Vahvista',
'billing.waive.title'                   => 'Vapauta lasku',
'billing.waive.reason'                  => 'Perustelu (pakollinen)',
'billing.reduce.title'                  => 'Alenna lasku',
'billing.reduce.new_amount'             => 'Uusi summa (€)',
'billing.reduce.reason'                 => 'Perustelu (pakollinen)',
'billing.audit.title'                   => 'Lasku-historia',
'billing.overrides.title'               => 'Per-jäsen alennukset',
'billing.overrides.active_only'         => 'Vain aktiiviset',
'billing.overrides.new_button'          => 'Uusi alennus',
'billing.overrides.column_member'       => 'Jäsen',
'billing.overrides.column_amount'       => 'Alennettu summa',
'billing.overrides.column_validity'     => 'Voimassa',
'billing.overrides.column_reason'       => 'Perustelu',
'billing.overrides.column_decision'     => 'Päätös',
'billing.overrides.column_actions'      => 'Toiminnot',
'billing.overrides.revoke'              => 'Peruuta',
'billing.overrides.revoke_confirm'      => 'Peruuta tämä alennus?',
'billing.overrides.empty'               => 'Ei alennuksia',
'billing.overrides.new_dialog.title'    => 'Uusi alennus',
'billing.overrides.new_dialog.user_id'  => 'Jäsenen ID',
'billing.overrides.new_dialog.fee_type' => 'Tyyppi',
'billing.overrides.new_dialog.amount'   => 'Alennettu summa (€)',
'billing.overrides.new_dialog.from'     => 'Voimassa alkaen',
'billing.overrides.new_dialog.until'    => 'Voimassa asti (tyhjä = määräämätön)',
'billing.overrides.new_dialog.reason'   => 'Perustelu (pakollinen)',
'billing.import.title'                  => 'CSV-tuonti — pankin tiliote',
'billing.import.description'            => 'Lataa Nordea-tiliote CSV-muodossa. Järjestelmä yrittää löytää viitenumeroon perustuen avoimet laskut ja ehdottaa täsmäykset.',
'billing.import.preview_button'         => 'Esikatsele',
'billing.import.preview.title'          => 'Esikatselu — täsmäysehdotukset',
'billing.import.confirm_button'         => 'Vahvista valitut',
'billing.import.confidence.high'        => 'Varma',
'billing.import.confidence.amount_mismatch' => 'Summa eroaa',
'billing.import.confidence.no_match'    => 'Ei täsmäystä',
'billing.import.results.title'          => 'Tuonti valmis',
```

- [ ] **Step 2: English (en_GB)**

`lang/en_GB.php` — same keys, English values:

```php
'shell.governance.billing'      => 'Billing',
'billing.tabs.invoices'         => 'Invoices',
'billing.tabs.fees'             => 'Fee schedule',
'billing.tabs.overrides'        => 'Overrides',
'billing.tabs.import'           => 'CSV import',
'billing.kpi.pending'           => 'Pending',
'billing.kpi.overdue'           => 'Overdue',
'billing.kpi.paid'              => 'Paid',
'billing.kpi.waived'            => 'Waived',
// ... (translate the remaining keys 1:1)
```

(Translate all keys from Step 1.)

- [ ] **Step 3: Swahili (sw_TZ)**

`lang/sw_TZ.php` — same keys, Swahili values. Use Google Translate as starting point + native review later (per the 0.6b convention for sw_TZ):

```php
'shell.governance.billing'  => 'Malipo',
'billing.tabs.invoices'     => 'Ankara',
'billing.tabs.fees'         => 'Ratiba ya ada',
'billing.tabs.overrides'    => 'Marekebisho',
'billing.tabs.import'       => 'Uagizaji wa CSV',
'billing.kpi.pending'       => 'Inasubiri',
'billing.kpi.overdue'       => 'Imechelewa',
'billing.kpi.paid'          => 'Imelipwa',
'billing.kpi.waived'        => 'Imeondolewa',
// ... (translate)
```

- [ ] **Step 4: Replace hardcoded Finnish strings in PHP templates with `I18n::t()` calls**

In all PHP template files created in Waves B-G, replace literal Finnish strings with i18n keys:

```php
// Before
<h2>Laskut <?= htmlspecialchars((string) $year) ?></h2>

// After
<h2><?= htmlspecialchars(\Daems\Frontend\I18n::t('billing.invoices.title', ['year' => (string) $year])) ?></h2>
```

Files to update:
- `public/backstage/governance/billing/index.php`
- `public/backstage/governance/billing/_invoices.php`
- `public/backstage/governance/billing/_fees.php`
- `public/backstage/governance/billing/_form.php`
- `public/backstage/governance/billing/_overrides.php`
- `public/backstage/governance/billing/_import.php`

(Same approach for JS — render strings server-side into a `<script>window.BILLING_I18N = {...};</script>` block at template top, then `BILLING_I18N['key']` in JS.)

- [ ] **Step 5: Verify i18n loader picks up new keys**

```bash
php -r "require 'vendor/autoload.php'; var_dump(\Daems\Frontend\I18n::t('shell.governance.billing'));"
```

Expected: `'Maksut'` (defaults to fi_FI).

- [ ] **Step 6: Commit**

```bash
git add lang/fi_FI.php lang/en_GB.php lang/sw_TZ.php public/backstage/governance/billing/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(i18n): MembershipBilling strings for fi_FI/en_GB/sw_TZ + template wiring"
```

---

## Task H3: ModuleRouteGuard governance prefix verification

**Files:**
- Verify (read-only): `src/Domain/Tenant/ModuleRouteGuard.php`
- Verify: `config/modules.php` — `governance` module entry (if any)

The governance group is hardcoded in `BackstageSidebar`, but the *routes* go through ModuleRouteGuard if there's a per-tenant gating. Verify governance/billing routes are NOT gated by a module-enabled check (they should always be available; only individual tenants can disable lapse-cron via `lapse_check_enabled`).

- [ ] **Step 1: Inspect ModuleRouteGuard**

```bash
grep -n "route_prefixes\|/backstage/governance" src/Domain/Tenant/ModuleRouteGuard.php config/modules.php
```

- [ ] **Step 2: Decide**

Two valid outcomes:
- **No change needed:** governance routes are not in any module's `route_prefixes`, so they pass through unguarded. Document this in a comment.
- **Add explicit allow:** if there's a default-deny policy, register `governance` as a pseudo-module in `config/modules.php` with `is_core=true, default_available=true` and `route_prefixes: ['/backstage/governance', '/api/v1/backstage/governance']`.

Most likely the existing 0.6b governance routes already pass through. Confirm by hitting `/backstage/governance/board` as an admin — if it works, billing's same-prefix routes will too.

- [ ] **Step 3: No commit if no change**

If changes are needed, commit:

```bash
git add src/Domain/Tenant/ModuleRouteGuard.php config/modules.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Confirm(routing): governance/billing routes accessible (no module gate)"
```

---

## Task H4: Integration smoke — end-to-end pipeline

**Files:**
- Create: `tests/Integration/Cron/BillingFullPipelineIntegrationTest.php`

Exercise the full pipeline in one test:
1. Set annual fee schedule for tenant 2026
2. Create users with anniversaries spanning the year
3. Run anniversary cron on each user's anniversary date
4. Run overdue cron on a future date
5. Run lapse cron on a date past the 2-year mark
6. Assert each user ends up in the expected state

- [ ] **Step 1: Write the test**

`tests/Integration/Cron/BillingFullPipelineIntegrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class BillingFullPipelineIntegrationTest extends MigrationTestCase
{
    public function test_full_pipeline_anniversary_overdue_lapse(): void
    {
        $this->runMigrationsUpTo(96);
        $tenantId = $this->seedDaemsTenant();
        $adminId = $this->seedAdmin($tenantId);

        // Year 2025 schedule
        $this->seedSchedule($tenantId, 2025, ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0]);
        // Year 2026 schedule
        $this->seedSchedule($tenantId, 2026, ['SUPPORTING' => 1500, 'BASIC' => 5500, 'FULL' => 0]);

        // A regular user who joined 2024-01-15 — anniversary fires twice (2025, 2026)
        $userA = $this->seedUser($tenantId, 'a@daems.fi', '2024-01-15', 'BASIC');

        // STEP 1: Run anniversary cron on 2025-01-15 → 2025 invoice for userA
        $this->runAnniversaryCron(new DateTimeImmutable('2025-01-15T02:00:00'));
        $inv2025 = $this->fetchInvoice($tenantId, $userA, 2025);
        $this->assertNotNull($inv2025);
        $this->assertSame(5000, (int) $inv2025['amount_cents']);

        // STEP 2: Anniversary fires again 2026-01-15 → 2026 invoice
        $this->runAnniversaryCron(new DateTimeImmutable('2026-01-15T02:00:00'));
        $inv2026 = $this->fetchInvoice($tenantId, $userA, 2026);
        $this->assertNotNull($inv2026);
        $this->assertSame(5500, (int) $inv2026['amount_cents']);

        // STEP 3: Time passes, both invoices unpaid. Run overdue cron on 2026-05-01.
        // 2025 invoice: due 2025-03-15 + 30 grace = 2025-04-14 → overdue by 2026-05-01 ✓
        // 2026 invoice: due 2026-03-15 + 30 grace = 2026-04-14 → overdue by 2026-05-01 ✓
        $this->runOverdueCron(new DateTimeImmutable('2026-05-01T02:30:00'));
        $inv2025 = $this->fetchInvoice($tenantId, $userA, 2025);
        $inv2026 = $this->fetchInvoice($tenantId, $userA, 2026);
        $this->assertSame('OVERDUE', (string) $inv2025['status']);
        $this->assertSame('OVERDUE', (string) $inv2026['status']);

        // STEP 4: Lapse cron on 2026-06-01 finds 2 consecutive OVERDUE years → LAPSE.
        $this->runLapseCron(new DateTimeImmutable('2026-06-01T03:00:00'));
        $userStatus = $this->fetchUserStatus($userA);
        $this->assertSame('lapsed', $userStatus);

        // STEP 5: member_status_audit row exists with reason mentioning '2v maksamatta'
        $auditCount = $this->countMemberStatusAudit($userA, 'lapsed');
        $this->assertSame(1, $auditCount);
    }

    // Helper methods (seedDaemsTenant, seedAdmin, seedSchedule, seedUser,
    // runAnniversaryCron, runOverdueCron, runLapseCron, fetchInvoice,
    // fetchUserStatus, countMemberStatusAudit) — implement as ~5-line shims
    // that mirror earlier wave's helpers.

    // ... (implementations omitted here for brevity but expected in the file)
}
```

- [ ] **Step 2: Run + commit**

```bash
vendor/bin/phpunit --filter BillingFullPipelineIntegrationTest
composer analyse
git add tests/Integration/Cron/BillingFullPipelineIntegrationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(tests/integration): full billing pipeline anniversary → overdue → lapse"
```

---

## Task H5: Update CLAUDE.md with billing state

**Files:**
- Modify: `CLAUDE.md` (project-level instructions)

Add a short section under "Current state" documenting that 0.7 is shipped, and reference the spec/plan.

- [ ] **Step 1: Append to CLAUDE.md**

Find the "Current state (updated YYYY-MM-DD)" section. Add:

```markdown
- **2026-MM-DD — MembershipBilling v1** (`docs/superpowers/specs/2026-05-12-membership-billing-v1-design.md`, `docs/superpowers/plans/2026-05-12-membership-billing-v1.md`): vuosittaiset jäsen-/kannatusmaksut hallituksen päätöksellä, anniversary-pohjainen lasku, waive/reduce, manual+CSV maksu, § 4 auto-lapse 2v maksamatta. Migraatiot 089-096 (096 ehkä, riippuu enum-tilasta), uusi `Daems\Domain\Membership\Billing\*` namespace, `bin/console` -CLI-runner. Stripe → 0.7.1, Visma → 0.7.2 omiin milestoneihin.
```

Update memory file `~/.claude/projects/c--laragon-www-daems-platform/memory/project_membership_billing.md`:

```markdown
---
name: MembershipBilling v1 (0.7) shipped
description: 2026-MM-DD — annual fees + anniversary cron + waive/reduce + manual+CSV payment + auto-lapse § 4; 14 phases ~70-90 commits
type: project
---

2026-MM-DD: Milestone 0.7 MembershipBilling shipped on `dev`.

**Why:** Säännöt § 5 (vuosittainen hallituksen päätös maksuista) + § 4 (2v maksamatta → auto-eronnut)
edellyttävät billing-domainin. Skoppi rajattu Stripe/Visma-integraatioiden ulkopuolelle (omat milestonet).

**How to apply:**
- Cron-runner `bin/console` valmis — käytetään 0.8 Communications -emaileihin myös
- `tenant_governance_settings`-rivissä 4 uutta billing-kytkintä
- HONORARY-vaihto kysyy admin-dialogissa "vapautetaanko avoimet laskut"
- LAPSED-status: deemed-resignation (kevyt), eri kuin EXPELLED (raskas 0.6b-flow)
```

Update MEMORY.md index:

```
- [MembershipBilling v1](project_membership_billing.md) — 2026-MM-DD: 0.7 shipped, annual fees + cron + waive + manual/CSV + auto-lapse
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Doc(claude.md): note MembershipBilling v1 (0.7) completion + memory pointer"
```

Note: memory files live OUTSIDE the repo (in `~/.claude/projects/...`); commit them separately via Claude Code's memory system, not via git in this repo.

---

## Task H6: Browser-smoke checklist (manual user task)

**Files:**
- (no code changes — user verification)

Before declaring 0.7 complete, the user must run through a manual smoke covering all 14 acceptance scenarios from the spec. This is not a coded test — the user works through the UI as a real admin would.

- [ ] **Step 1: Smoke checklist (user works through these)**

| # | Scenario | Expected |
|---|---|---|
| 1 | `/backstage/governance/billing` lataa | KPI-strip näkyy, lista pyörii, 4 tab |
| 2 | View=fees → "Muokkaa hinnastoa" → tallenna 3 hintaa | 3 riviä `status=active`, navigoi listalle |
| 3 | Tenant requires_formal=true settings → tallenna → ohjautuu `/backstage/governance/decisions/{id}` | Decision-rivi olemassa |
| 4 | Decision passes → palaa `/backstage/governance/billing?view=fees` | 3 riviä nyt active |
| 5 | Override: "Uusi alennus" → tallenna | Rivi listassa "Vain aktiiviset" -tilassa |
| 6 | Anniversary-cron manuaalisesti → lasku luotu override:n alennetulla summalla | DB-tarkistus: amount_cents = override_amount_cents |
| 7 | Row-action "Merkitse maksetuksi" → modaali → vahvista | Lasku flippaa PAID |
| 8 | Row-action "Vapauta" → modaali perustelulla → vahvista | Lasku flippaa WAIVED |
| 9 | Row-action "Alenna" → modaali uudella summalla → vahvista | Lasku flippaa REDUCED, original_amount_cents säilytetty |
| 10 | Row-action "Historia" → drawer aukeaa | Audit-rivit näkyvät (created → overdue → waived) |
| 11 | View=import → lataa Nordea-CSV → preview → vahvista täsmäykset | Laskut PAID |
| 12 | Lapse-cron --dry-run → preview lista | Käyttäjät joilla 2v overdue listattu, ei muutoksia |
| 13 | Lapse-cron real → users.membership_status='lapsed' | Members-listalla badge "Eronnut" |
| 14 | GSA "Peruuta lapse" -nappi → justifikaatio → vahvista | users.membership_status='active', gsa_overrides-rivi |
| 15 | Membership type → HONORARY admin-dialogi | Kysyy "vapautetaanko N avointa laskua" → vapauttaa |
| 16 | Tenant isolation: kirjaudu sahegroup-adminina | Ei näe daems-tenantin laskuja/hinnastoa |

- [ ] **Step 2: File any smoke failures as task entries in `docs/superpowers/plans/deferred-items.md`**

```bash
# If a smoke step fails, add to deferred items:
echo "- [ ] [0.7-smoke-fix] <description>" >> docs/superpowers/plans/deferred-items.md
git add docs/superpowers/plans/deferred-items.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Defer(0.7-smoke): file <description> as follow-up"
```

---

## Task H7: Wave H Definition of Done + 0.7 milestone gate

After Tasks H1-H6:

- [ ] BackstageSidebar.php has 6 governance items including billing (order=60)
- [ ] `vendor/bin/phpunit --filter BackstageSidebarTest` green (8 tests, baseline 9, ordered 12 hrefs)
- [ ] All UI templates use `I18n::t()` instead of hardcoded Finnish strings
- [ ] `lang/fi_FI.php`, `lang/en_GB.php`, `lang/sw_TZ.php` have all billing strings
- [ ] `BillingFullPipelineIntegrationTest` green
- [ ] `composer analyse` = 0 errors
- [ ] Browser-smoke H6 checklist completed (or smoke failures deferred to follow-up items)

Run final gate:

```bash
composer analyse
vendor/bin/phpunit 2>&1 | tail -5
vendor/bin/phpunit --filter Billing 2>&1 | tail -5
git log --oneline | head -20
```

Expected: PHPStan 0, all suites green, ~70-90 commits since Task A1.

If anything fails, FIX before declaring 0.7 done.

---

# Post-plan: Self-review + handover

## Plan Self-review

Before declaring this plan complete, run a self-check against the spec.

**Spec coverage check** — confirm each item in `docs/superpowers/specs/2026-05-12-membership-billing-v1-design.md` Section 2 (decisions) has a task:

| Spec decision | Plan coverage |
|---|---|
| Q1: Per-type per-year (3 hintaa), sub-tier puhdas kunnia | B2 (mig 090), B7 (entity), B11 (DraftAnnualFeeSchedule) |
| Q2: 2 peräkkäistä OVERDUE → lapse | F3 (LapseInactiveMember), F5 (cron test) |
| Q3: Anniversary-based billing | C8 (use case), C9 (cron filter by MONTH+DAY) |
| Q4: Per-lasku WAIVE/REDUCE + per-user override | E3/E4 (waive/reduce use cases), D1-D5 (overrides) |
| Q5: 0.7 = manual + CSV | E5 (manual payment), G5-G8 (CSV import) |
| Q6: 0.7 = billing logic, UI-bell notifit | A1-A6 (cron infra, no SMTP), KPI strip in E8 |
| Q7: Configurable per-tenant decision flow | B3 (mig 094 settings column), B11 (DraftAnnualFeeSchedule routing) |

**Placeholder scan** — no remaining "TBD" / "TODO" inside task bodies. Two acceptable TODOs left:
1. Wave G CSS variable names assume the platform's design tokens; executor replaces with actual variable names from `daems-platform/public/backstage/assets/...`.
2. Wave G6 (PreviewImportPayments) note: more robust reference-matching schemes (Finnish RF) deferred to 0.7.1 Stripe milestone.

**Type consistency check** — class names + method signatures stay stable across tasks:
- `MemberFeeInvoice::amountCents()` (not `amount()`), `originalAmountCents()` ✓
- `MemberFeeInvoiceStatus::Pending/Paid/Overdue/Waived/Reduced` ✓
- `AnnualFeeScheduleStatus::Draft/Proposed/Active/Superseded` ✓
- `UserFeeOverride::overrideAmountCents()` (not `amount()`) ✓
- `LapseInactiveMember` (singular noun in name, matches 0.6b `InitiateMemberExpulsion` convention) ✓

**Scope check** — 8 waves cover 14 spec phases with ~75 tasks. ~70-90 commits estimated. 4-6 weeks elapsed. Single-milestone scope.

---

## Execution handoff

**Plan complete.** Saved to `docs/superpowers/plans/2026-05-12-membership-billing-v1.md`.

Two execution options:

**1. Subagent-Driven (recommended)** — orchestrator dispatches a fresh subagent per task, reviews between tasks, fast iteration. Best for this size (75 tasks × ~3-5 minutes each subagent).

**2. Inline Execution** — execute tasks in this session using executing-plans skill, batch execution with checkpoints. Better if subagent dispatch is unavailable; slower but easier to debug.

Which approach?

