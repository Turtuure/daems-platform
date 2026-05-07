<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Backstage;

use PHPUnit\Framework\TestCase;

/**
 * Spec AC-11: backstage must refuse access for suspended tenants with a 503.
 *
 * `_guard.php` is a procedural script that calls exit() on every branch — a
 * direct require would kill the test process. Instead we run it under PHP CLI
 * in a subprocess, feeding it a stub `$GLOBALS['daems_backstage_tenant']`
 * that is suspended, and assert the resulting stdout body.
 *
 * Note: under PHP CLI, http_response_code() does not produce visible output;
 * the SUT also calls echo + exit, so capturing stdout proves the suspension
 * branch fired.
 */
final class SuspendedTenantGuardTest extends TestCase
{
    private static string $guardPath;
    private static string $autoloadPath;

    public static function setUpBeforeClass(): void
    {
        self::$guardPath = realpath(__DIR__ . '/../../../public/backstage/_guard.php')
            ?: throw new \RuntimeException('cannot locate _guard.php');
        self::$autoloadPath = realpath(__DIR__ . '/../../../vendor/autoload.php')
            ?: throw new \RuntimeException('cannot locate vendor/autoload.php');
    }

    public function test_suspended_tenant_renders_503_with_reason(): void
    {
        $script = <<<'PHP'
<?php
require __AUTOLOAD_PATH__;

session_start();
$_SESSION['user'] = ['is_platform_admin' => true]; // even an admin should get 503
$_SERVER['REQUEST_URI'] = '/backstage';

$tenant = new \Daems\Domain\Tenant\Tenant(
    id: \Daems\Domain\Tenant\TenantId::fromString('01958000-0000-7000-8000-000000000001'),
    slug: \Daems\Domain\Tenant\TenantSlug::fromString('acme'),
    name: 'Acme',
    createdAt: new \DateTimeImmutable('2026-04-01T00:00:00+00:00'),
    suspendedAt: new \DateTimeImmutable('2026-05-01T10:00:00+00:00'),
    suspendedReason: 'maintenance window',
);
$GLOBALS['daems_backstage_tenant'] = $tenant;

require __GUARD_PATH__;
PHP;

        $output = $this->runGuardScript($script);
        self::assertStringContainsString('Tenant suspended', $output, "guard output: {$output}");
        self::assertStringContainsString('maintenance window', $output);
    }

    public function test_non_suspended_tenant_does_not_short_circuit(): void
    {
        // For a non-suspended tenant the guard should NOT print the 503 page;
        // instead it falls through to the role check (admin passes silently).
        $script = <<<'PHP'
<?php
require __AUTOLOAD_PATH__;

session_start();
$_SESSION['user'] = ['is_platform_admin' => true];
$_SERVER['REQUEST_URI'] = '/backstage';

$tenant = new \Daems\Domain\Tenant\Tenant(
    id: \Daems\Domain\Tenant\TenantId::fromString('01958000-0000-7000-8000-000000000001'),
    slug: \Daems\Domain\Tenant\TenantSlug::fromString('acme'),
    name: 'Acme',
    createdAt: new \DateTimeImmutable('2026-04-01T00:00:00+00:00'),
    suspendedAt: null,
    suspendedReason: null,
);
$GLOBALS['daems_backstage_tenant'] = $tenant;

require __GUARD_PATH__;
echo "FELL_THROUGH";
PHP;

        $output = $this->runGuardScript($script);
        self::assertStringNotContainsString('Tenant suspended', $output, "guard output: {$output}");
        self::assertStringContainsString('FELL_THROUGH', $output);
    }

    private function runGuardScript(string $script): string
    {
        $resolved = strtr($script, [
            '__GUARD_PATH__'    => var_export(self::$guardPath, true),
            '__AUTOLOAD_PATH__' => var_export(self::$autoloadPath, true),
        ]);
        $tmpScript = sys_get_temp_dir() . '/daems-suspended-guard-' . uniqid('', true) . '.php';
        file_put_contents($tmpScript, $resolved);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpScript) . ' 2>&1';
            return (string) shell_exec($cmd);
        } finally {
            @unlink($tmpScript);
        }
    }
}
