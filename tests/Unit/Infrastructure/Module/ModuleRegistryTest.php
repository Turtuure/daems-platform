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

    public function test_register_autoloader_maps_module_namespace_to_src_dir(): void
    {
        $modDir = $this->tmp . '/widgets';
        mkdir($modDir . '/backend/src', 0777, true);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'widgets',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\Widgets\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ]));
        file_put_contents($modDir . '/backend/src/Hello.php',
            "<?php namespace DaemsModule\\Widgets; class Hello { public function name(): string { return 'widgets'; } }"
        );

        $loader = new \Composer\Autoload\ClassLoader();
        $loader->register();

        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $r->registerAutoloader($loader);

        self::assertTrue(class_exists('DaemsModule\\Widgets\\Hello'));
        $obj = new \DaemsModule\Widgets\Hello();
        self::assertSame('widgets', $obj->name());

        $loader->unregister();
    }

    public function test_register_bindings_invokes_module_binding_closure(): void
    {
        $modDir = $this->tmp . '/foo';
        mkdir($modDir . '/backend', 0777, true);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'foo', 'version' => '1.0.0', 'namespace' => 'DaemsModule\\Foo\\',
            'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
        ]));
        file_put_contents($modDir . '/backend/bindings.php',
            "<?php return function (\$container) { \$container->bind('foo.invoked', fn() => 'yes'); };"
        );

        $container = new \Daems\Infrastructure\Framework\Container\Container();
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $r->registerBindings($container, ModuleRegistry::PROD);
        self::assertSame('yes', $container->make('foo.invoked'));
    }

    public function test_register_bindings_in_test_mode_uses_test_bindings_file(): void
    {
        $modDir = $this->tmp . '/foo';
        mkdir($modDir . '/backend', 0777, true);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'foo', 'version' => '1.0.0', 'namespace' => 'DaemsModule\\Foo\\',
            'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
        ]));
        file_put_contents($modDir . '/backend/bindings.php',
            "<?php return function (\$c) { \$c->bind('foo.flavor', fn() => 'prod'); };"
        );
        file_put_contents($modDir . '/backend/bindings.test.php',
            "<?php return function (\$c) { \$c->bind('foo.flavor', fn() => 'test'); };"
        );

        $container = new \Daems\Infrastructure\Framework\Container\Container();
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $r->registerBindings($container, ModuleRegistry::TEST);
        self::assertSame('test', $container->make('foo.flavor'));
    }

    public function test_register_bindings_in_test_mode_falls_back_to_prod_when_no_test_file(): void
    {
        $modDir = $this->tmp . '/foo';
        mkdir($modDir . '/backend', 0777, true);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'foo', 'version' => '1.0.0', 'namespace' => 'DaemsModule\\Foo\\',
            'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
        ]));
        file_put_contents($modDir . '/backend/bindings.php',
            "<?php return function (\$c) { \$c->bind('foo.flavor', fn() => 'prod'); };"
        );

        $container = new \Daems\Infrastructure\Framework\Container\Container();
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $r->registerBindings($container, ModuleRegistry::TEST);
        self::assertSame('prod', $container->make('foo.flavor'));
    }

    public function test_migration_paths_lists_each_modules_migrations_dir(): void
    {
        foreach (['a', 'b'] as $name) {
            $d = $this->tmp . '/' . $name;
            mkdir($d . '/backend/migrations', 0777, true);
            file_put_contents($d . '/module.json', json_encode([
                'name' => $name, 'version' => '1.0.0',
                'namespace' => 'DaemsModule\\' . ucfirst($name) . '\\',
                'src_path' => 'backend/src/', 'bindings' => 'backend/bindings.php',
                'routes' => 'backend/routes.php', 'migrations_path' => 'backend/migrations/',
            ]));
        }
        $r = new ModuleRegistry();
        $r->discover($this->tmp);
        $paths = $r->migrationPaths();
        self::assertCount(2, $paths);
        self::assertStringContainsString('/a/backend/migrations/', $paths[0]);
        self::assertStringContainsString('/b/backend/migrations/', $paths[1]);
    }

    public function test_merges_platform_metadata_from_catalog(): void
    {
        $this->writeModule('events', []);
        $catalog = $this->writeCatalog([
            'events' => [
                'category'          => 'content',
                'name_key'          => 'modules.events.name',
                'description_key'   => 'modules.events.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'new \\Daems\\Infrastructure\\Module\\SidebarEntry(group: "content", order: 20, icon: "calendar", href: "/backstage/events")',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/events"], api: ["/api/v1/events"])',
                'depends_on'        => [],
            ],
        ]);

        $r = new ModuleRegistry();
        $r->discover($this->tmp, $catalog);

        $events = $r->get('events');
        self::assertNotNull($events);
        self::assertSame('content', $events->category());
        self::assertSame('modules.events.name', $events->nameKey());
        self::assertTrue($events->defaultAvailable());
        self::assertNotNull($events->sidebar());
        self::assertSame('content', $events->sidebar()->group());
        self::assertSame(['/backstage/events'], $events->routePrefixes()->backstagePrefixes());
    }

    public function test_rejects_catalog_entry_for_undiscovered_module(): void
    {
        $catalog = $this->writeCatalog([
            'ghost' => [
                'category'          => 'x',
                'name_key'          => 'modules.ghost.name',
                'description_key'   => 'modules.ghost.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: [], api: [])',
                'depends_on'        => [],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/'ghost'.*not discoverable/");
        (new ModuleRegistry())->discover($this->tmp, $catalog);
    }

    public function test_rejects_dependency_cycle(): void
    {
        $this->writeModule('a', []);
        $this->writeModule('b', []);
        $catalog = $this->writeCatalog([
            'a' => [
                'category'          => 'x',
                'name_key'          => 'modules.a.name',
                'description_key'   => 'modules.a.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/a"], api: [])',
                'depends_on'        => ['b'],
            ],
            'b' => [
                'category'          => 'x',
                'name_key'          => 'modules.b.name',
                'description_key'   => 'modules.b.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/b"], api: [])',
                'depends_on'        => ['a'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/dependency cycle/i');
        (new ModuleRegistry())->discover($this->tmp, $catalog);
    }

    public function test_rejects_unknown_dependency(): void
    {
        $this->writeModule('a', []);
        $catalog = $this->writeCatalog([
            'a' => [
                'category'          => 'x',
                'name_key'          => 'modules.a.name',
                'description_key'   => 'modules.a.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/a"], api: [])',
                'depends_on'        => ['missing'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/'missing'.*not in this deployment/");
        (new ModuleRegistry())->discover($this->tmp, $catalog);
    }

    public function test_rejects_overlapping_route_prefixes(): void
    {
        $this->writeModule('a', []);
        $this->writeModule('b', []);
        $catalog = $this->writeCatalog([
            'a' => [
                'category'          => 'x',
                'name_key'          => 'modules.a.name',
                'description_key'   => 'modules.a.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: [], api: ["/api/v1/shared"])',
                'depends_on'        => [],
            ],
            'b' => [
                'category'          => 'x',
                'name_key'          => 'modules.b.name',
                'description_key'   => 'modules.b.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: [], api: ["/api/v1/shared/extra"])',
                'depends_on'        => [],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/overlapping route_prefixes/");
        (new ModuleRegistry())->discover($this->tmp, $catalog);
    }

    public function test_rejects_is_core_without_default_available(): void
    {
        $this->writeModule('a', []);
        $catalog = $this->writeCatalog([
            'a' => [
                'category'          => 'x',
                'name_key'          => 'modules.a.name',
                'description_key'   => 'modules.a.description',
                'is_core'           => true,
                'default_available' => false,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/a"], api: [])',
                'depends_on'        => [],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/is_core=true requires default_available=true/');
        (new ModuleRegistry())->discover($this->tmp, $catalog);
    }

    public function test_find_owner_of_path_picks_longest_match_across_modules(): void
    {
        $this->writeModule('events', []);
        $this->writeModule('insights', []);
        $catalog = $this->writeCatalog([
            'events' => [
                'category'          => 'content',
                'name_key'          => 'modules.events.name',
                'description_key'   => 'modules.events.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/events"], api: ["/api/v1/events"])',
                'depends_on'        => [],
            ],
            'insights' => [
                'category'          => 'content',
                'name_key'          => 'modules.insights.name',
                'description_key'   => 'modules.insights.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/insights"], api: ["/api/v1/insights"])',
                'depends_on'        => [],
            ],
        ]);

        $r = new ModuleRegistry();
        $r->discover($this->tmp, $catalog);

        $owner = $r->findOwnerOfPath('/api/v1/events/42');
        self::assertNotNull($owner);
        self::assertSame('events', $owner->name());

        $owner = $r->findOwnerOfPath('/backstage/insights/edit');
        self::assertNotNull($owner);
        self::assertSame('insights', $owner->name());

        self::assertNull($r->findOwnerOfPath('/api/v1/unknown'));
    }

    public function test_core_and_toggleable_module_lists(): void
    {
        $this->writeModule('core-mod', []);
        $this->writeModule('opt-mod', []);
        $catalog = $this->writeCatalog([
            'core-mod' => [
                'category'          => 'core',
                'name_key'          => 'modules.core-mod.name',
                'description_key'   => 'modules.core-mod.description',
                'is_core'           => true,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/core"], api: [])',
                'depends_on'        => [],
            ],
            'opt-mod' => [
                'category'          => 'content',
                'name_key'          => 'modules.opt-mod.name',
                'description_key'   => 'modules.opt-mod.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/opt"], api: [])',
                'depends_on'        => [],
            ],
        ]);

        $r = new ModuleRegistry();
        $r->discover($this->tmp, $catalog);

        self::assertSame(['core-mod'], $r->coreModules());
        self::assertSame(['opt-mod'], $r->toggleableModules());
        self::assertSame('core', $r->categoryOf('core-mod'));
        self::assertNull($r->categoryOf('nonexistent'));
    }

    public function test_dependents_and_dependencies(): void
    {
        $this->writeModule('lib', []);
        $this->writeModule('app', []);
        $catalog = $this->writeCatalog([
            'lib' => [
                'category'          => 'x',
                'name_key'          => 'modules.lib.name',
                'description_key'   => 'modules.lib.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/lib"], api: [])',
                'depends_on'        => [],
            ],
            'app' => [
                'category'          => 'x',
                'name_key'          => 'modules.app.name',
                'description_key'   => 'modules.app.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/app"], api: [])',
                'depends_on'        => ['lib'],
            ],
        ]);

        $r = new ModuleRegistry();
        $r->discover($this->tmp, $catalog);

        self::assertSame(['lib'], $r->dependencies('app'));
        self::assertSame([], $r->dependencies('lib'));
        self::assertSame(['app'], $r->dependents('lib'));
        self::assertSame([], $r->dependents('app'));
        self::assertSame([], $r->dependencies('unknown'));
    }

    /**
     * Write a module.json under $this->tmp/$name with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function writeModule(string $name, array $overrides): void
    {
        $d = $this->tmp . '/' . $name;
        if (!is_dir($d)) {
            mkdir($d, 0777, true);
        }
        $data = array_merge([
            'name' => $name,
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\' . ucfirst(str_replace('-', '', $name)) . '\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ], $overrides);
        file_put_contents($d . '/module.json', json_encode($data));
    }

    /**
     * Write a catalog file under $this->tmp/catalog.php whose return value is
     * an associative array. Sidebar / route_prefixes / depends_on entries are
     * passed as raw PHP source strings so the test can express live objects
     * through a simple data shape.
     *
     * @param array<string, array<string, mixed>> $entries
     */
    private function writeCatalog(array $entries): string
    {
        $lines = ["<?php declare(strict_types=1);", "return ["];
        foreach ($entries as $name => $entry) {
            $lines[] = "  '" . $name . "' => [";
            foreach ($entry as $key => $val) {
                if ($key === 'sidebar' || $key === 'route_prefixes') {
                    // Raw PHP source.
                    $lines[] = "    '" . $key . "' => " . $val . ",";
                } elseif ($key === 'depends_on') {
                    $items = array_map(fn(string $s): string => "'" . $s . "'", (array) $val);
                    $lines[] = "    '" . $key . "' => [" . implode(', ', $items) . "],";
                } elseif (is_bool($val)) {
                    $lines[] = "    '" . $key . "' => " . ($val ? 'true' : 'false') . ",";
                } elseif ($val === null) {
                    $lines[] = "    '" . $key . "' => null,";
                } else {
                    $lines[] = "    '" . $key . "' => '" . $val . "',";
                }
            }
            $lines[] = "  ],";
        }
        $lines[] = "];";
        $path = $this->tmp . '/catalog.php';
        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    public function test_rejects_unknown_name_key_against_lang_file(): void
    {
        $this->writeModule('events', []);
        $catalog = $this->writeCatalog([
            'events' => [
                'category'          => 'content',
                'name_key'          => 'modules.events.bogus_key',
                'description_key'   => 'modules.events.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/events"], api: [])',
                'depends_on'        => [],
            ],
        ]);
        // Stub lang file with only the description_key, NOT the bogus name_key.
        $langPath = $this->tmp . '/lang.php';
        file_put_contents(
            $langPath,
            "<?php return ['modules.events.description' => 'desc'];",
        );

        $r = new ModuleRegistry();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/name_key.*bogus_key.*not found/");
        $r->discover($this->tmp, $catalog, $langPath);
    }

    public function test_rejects_unknown_description_key_against_lang_file(): void
    {
        $this->writeModule('events', []);
        $catalog = $this->writeCatalog([
            'events' => [
                'category'          => 'content',
                'name_key'          => 'modules.events.name',
                'description_key'   => 'modules.events.bogus_description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/events"], api: [])',
                'depends_on'        => [],
            ],
        ]);
        $langPath = $this->tmp . '/lang.php';
        file_put_contents(
            $langPath,
            "<?php return ['modules.events.name' => 'Events'];",
        );

        $r = new ModuleRegistry();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/description_key.*bogus_description.*not found/");
        $r->discover($this->tmp, $catalog, $langPath);
    }

    public function test_passes_when_all_keys_exist_in_lang_file(): void
    {
        $this->writeModule('events', []);
        $catalog = $this->writeCatalog([
            'events' => [
                'category'          => 'content',
                'name_key'          => 'modules.events.name',
                'description_key'   => 'modules.events.description',
                'is_core'           => false,
                'default_available' => true,
                'sidebar'           => 'null',
                'route_prefixes'    => 'new \\Daems\\Infrastructure\\Module\\RoutePrefixes(backstage: ["/backstage/events"], api: [])',
                'depends_on'        => [],
            ],
        ]);
        $langPath = $this->tmp . '/lang.php';
        file_put_contents(
            $langPath,
            "<?php return ['modules.events.name' => 'Events', 'modules.events.description' => 'desc'];",
        );

        $r = new ModuleRegistry();
        $r->discover($this->tmp, $catalog, $langPath);
        self::assertNotNull($r->get('events'));
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
