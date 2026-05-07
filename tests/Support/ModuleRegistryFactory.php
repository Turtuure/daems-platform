<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Builds a real ModuleRegistry from temp module.json files + a temp
 * config/modules.php — used by Application-layer use case tests.
 *
 * Mirrors the technique in TenantModuleResolverTest::makeRegistry but lives
 * in Support so multiple use case test suites can share the implementation.
 *
 * Each spec entry produces:
 *   - tmp/modules/<name>/module.json      (manifest)
 *   - tmp/modules/<name>/bindings.php     (empty closure)
 *   - tmp/modules/<name>/routes.php       (empty closure)
 *   - tmp/modules/<name>/{src,migrations} (empty dirs)
 * plus a tmp/modules.php catalog supplying platform metadata.
 *
 * Callers MUST keep the returned [registry, root] pair alive for the duration
 * of the test (the temp dir is removed by the test's tearDown).
 */
final class ModuleRegistryFactory
{
    /**
     * @param list<array{name: string, isCore?: bool, defaultAvailable?: bool, dependsOn?: list<string>, category?: ?string, nameKey?: ?string, descriptionKey?: ?string, sidebar?: ?array{group: string, order: int, icon: string, href: string}}> $specs
     * @return array{0: ModuleRegistry, 1: string} [registry, tempRootDir]
     */
    public static function build(array $specs): array
    {
        $root = sys_get_temp_dir() . '/dr_mr_' . bin2hex(random_bytes(4));
        @mkdir($root, 0o777, true);

        $modulesDir = $root . '/modules';
        @mkdir($modulesDir, 0o777, true);

        foreach ($specs as $spec) {
            $name = $spec['name'];
            $dir = $modulesDir . '/' . $name;
            @mkdir($dir . '/src', 0o777, true);
            @mkdir($dir . '/migrations', 0o777, true);
            file_put_contents($dir . '/bindings.php', "<?php\nreturn function(\$c) {};\n");
            file_put_contents($dir . '/routes.php', "<?php\nreturn function(\$r, \$c) {};\n");
            file_put_contents($dir . '/module.json', json_encode([
                'name'             => $name,
                'version'          => '0.0.1',
                'namespace'        => 'DaemsModule\\' . ucfirst($name) . '\\',
                'src_path'         => 'src',
                'bindings'         => 'bindings.php',
                'routes'           => 'routes.php',
                'migrations_path'  => 'migrations',
            ]) ?: '{}');
        }

        $catalogLines = [
            "<?php",
            "use Daems\\Infrastructure\\Module\\RoutePrefixes;",
            "use Daems\\Infrastructure\\Module\\SidebarEntry;",
            "return [",
        ];
        foreach ($specs as $spec) {
            $name = $spec['name'];
            $isCore = ($spec['isCore'] ?? false) ? 'true' : 'false';
            $defaultAvailable = ($spec['defaultAvailable'] ?? ($spec['isCore'] ?? false)) ? 'true' : 'false';
            $dependsOn = $spec['dependsOn'] ?? [];
            $depsLiteral = '[' . implode(', ', array_map(static fn (string $d) => "'{$d}'", $dependsOn)) . ']';
            $category = $spec['category'] ?? null;
            $nameKey  = $spec['nameKey']  ?? null;
            $descKey  = $spec['descriptionKey'] ?? null;
            $catRaw   = $category === null ? 'null' : "'{$category}'";
            $nkRaw    = $nameKey  === null ? 'null' : "'{$nameKey}'";
            $dkRaw    = $descKey  === null ? 'null' : "'{$descKey}'";
            $sidebar = $spec['sidebar'] ?? null;
            if ($sidebar === null) {
                $sidebarLiteral = 'null';
            } else {
                $sg = addslashes($sidebar['group']);
                $si = addslashes($sidebar['icon']);
                $sh = addslashes($sidebar['href']);
                $so = (int) $sidebar['order'];
                $sidebarLiteral = "new SidebarEntry(group: '{$sg}', order: {$so}, icon: '{$si}', href: '{$sh}')";
            }
            $catalogLines[] = "  '{$name}' => [";
            $catalogLines[] = "    'category' => {$catRaw},";
            $catalogLines[] = "    'name_key' => {$nkRaw},";
            $catalogLines[] = "    'description_key' => {$dkRaw},";
            $catalogLines[] = "    'is_core' => {$isCore},";
            $catalogLines[] = "    'default_available' => {$defaultAvailable},";
            $catalogLines[] = "    'sidebar' => {$sidebarLiteral},";
            $catalogLines[] = "    'route_prefixes' => new RoutePrefixes(backstage: ['/backstage/{$name}'], api: ['/api/v1/{$name}']),";
            $catalogLines[] = "    'depends_on' => {$depsLiteral},";
            $catalogLines[] = "  ],";
        }
        $catalogLines[] = "];";

        $catalogPath = $root . '/modules.php';
        file_put_contents($catalogPath, implode("\n", $catalogLines));

        $registry = new ModuleRegistry();
        $registry->discover($modulesDir, $catalogPath);
        return [$registry, $root];
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
