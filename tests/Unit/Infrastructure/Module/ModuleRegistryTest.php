<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\ManifestValidationException;
use Daems\Infrastructure\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/dr_module_test_' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function test_discovers_no_modules_in_empty_dir(): void
    {
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        self::assertSame([], $r->all());
    }

    public function test_discovers_one_module(): void
    {
        $modDir = $this->tmp . '/insights';
        mkdir($modDir);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'insights',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\Insights\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ]));

        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $modules = $r->all();
        self::assertCount(1, $modules);
        self::assertNotNull($r->get('insights'));
        self::assertSame('insights', $r->get('insights')->name());
    }

    public function test_skips_dirs_without_manifest(): void
    {
        mkdir($this->tmp . '/notamodule');
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        self::assertSame([], $r->all());
    }

    public function test_throws_on_duplicate_module_name(): void
    {
        foreach (['a', 'b'] as $sub) {
            $d = $this->tmp . '/' . $sub;
            mkdir($d);
            file_put_contents($d . '/module.json', json_encode([
                'name' => 'duplicate',
                'version' => '1.0.0',
                'namespace' => 'DaemsModule\\Duplicate\\',
                'src_path' => 'src/',
                'bindings' => 'b.php',
                'routes' => 'r.php',
                'migrations_path' => 'm/',
            ]));
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/duplicate.*module name/i');
        (new ModuleRegistry())->discover($this->tmp);
    }

    public function test_propagates_manifest_validation_errors(): void
    {
        $d = $this->tmp . '/broken';
        mkdir($d);
        file_put_contents($d . '/module.json', json_encode(['version' => '1.0.0'])); // missing name
        $this->expectException(ManifestValidationException::class);
        (new ModuleRegistry())->discover($this->tmp);
    }

    public function test_throws_on_invalid_json(): void
    {
        $d = $this->tmp . '/broken-json';
        mkdir($d);
        file_put_contents($d . '/module.json', '{not valid');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid json/i');
        (new ModuleRegistry())->discover($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
