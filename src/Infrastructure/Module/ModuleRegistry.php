<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

use Composer\Autoload\ClassLoader;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;

final class ModuleRegistry
{
    public const PROD = 'prod';
    public const TEST = 'test';

    /** @var array<string, ModuleManifest> */
    private array $modules = [];

    /**
     * Scan $modulesDir/* for module.json files. For each one found, parse,
     * validate, and store keyed by module name. Throws on duplicate names
     * or invalid JSON. ManifestValidationException propagates through.
     *
     * If $platformCatalogPath is provided AND the file exists, after the
     * module.json scan completes we merge platform-level metadata from that
     * file into each manifest and run validateGraph(). Catalog entries that
     * reference an undiscovered module name throw — surfacing the misconfig
     * at boot, not at first request.
     */
    public function discover(string $modulesDir, ?string $platformCatalogPath = null): void
    {
        $real = realpath($modulesDir);
        if ($real === false || !is_dir($real)) {
            return; // No modules dir on this host — nothing to discover.
        }
        $entries = scandir($real) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = $real . '/' . $entry . '/module.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $raw = (string) file_get_contents($manifestPath);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Invalid JSON in {$manifestPath}");
            }
            $manifest = ModuleManifest::fromArray($data, dirname($manifestPath));
            if (isset($this->modules[$manifest->name()])) {
                throw new \RuntimeException(
                    "Duplicate module name '{$manifest->name()}' (already registered from another directory)"
                );
            }
            $this->modules[$manifest->name()] = $manifest;
        }

        if ($platformCatalogPath !== null && is_file($platformCatalogPath)) {
            $this->mergePlatformMetadata($platformCatalogPath);
            $this->validateGraph();
        }
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $name): ?ModuleManifest
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * Register each discovered module's namespace with Composer's runtime
     * ClassLoader. After this returns, classes under DaemsModule\<Name>\
     * become resolvable to files under modules/<name>/<src_path>.
     */
    public function registerAutoloader(ClassLoader $loader): void
    {
        foreach ($this->modules as $manifest) {
            $loader->addPsr4($manifest->namespace(), $manifest->absoluteSrcPath());
        }
    }

    /**
     * Invoke each discovered module's bindings file. The file must `return`
     * a closure that takes the Container.
     *
     * @param self::PROD|self::TEST $mode  PROD loads bindings.php; TEST tries
     *                                     bindings.test.php first and falls
     *                                     back to bindings.php if absent.
     */
    public function registerBindings(Container $container, string $mode = self::PROD): void
    {
        foreach ($this->modules as $manifest) {
            $path = $mode === self::TEST && is_file($manifest->absoluteTestBindingsPath())
                  ? $manifest->absoluteTestBindingsPath()
                  : $manifest->absoluteBindingsPath();
            if (!is_file($path)) {
                throw new \RuntimeException("Module '{$manifest->name()}' bindings file not found: {$path}");
            }
            $closure = require $path;
            if (!$closure instanceof \Closure) {
                throw new \RuntimeException("Module '{$manifest->name()}' bindings file must return a Closure");
            }
            $closure($container);
        }
    }

    /**
     * Invoke each discovered module's routes file. The file must `return`
     * a closure that takes (Router, Container).
     */
    public function registerRoutes(Router $router, Container $container): void
    {
        foreach ($this->modules as $manifest) {
            $path = $manifest->absoluteRoutesPath();
            if (!is_file($path)) {
                throw new \RuntimeException("Module '{$manifest->name()}' routes file not found: {$path}");
            }
            $closure = require $path;
            if (!$closure instanceof \Closure) {
                throw new \RuntimeException("Module '{$manifest->name()}' routes file must return a Closure");
            }
            $closure($router, $container);
        }
    }

    /**
     * Return absolute paths to each module's migrations directory.
     *
     * @return list<string>
     */
    public function migrationPaths(): array
    {
        $paths = [];
        foreach ($this->modules as $manifest) {
            $paths[] = $manifest->absoluteMigrationsPath();
        }
        return $paths;
    }

    /**
     * Returns the category of $name, or null if the module is unknown OR has
     * no platform-level metadata attached.
     */
    public function categoryOf(string $name): ?string
    {
        return isset($this->modules[$name]) ? $this->modules[$name]->category() : null;
    }

    /** @return list<string> */
    public function coreModules(): array
    {
        $out = [];
        foreach ($this->modules as $name => $manifest) {
            if ($manifest->isCore()) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public function toggleableModules(): array
    {
        $out = [];
        foreach ($this->modules as $name => $manifest) {
            if (!$manifest->isCore()) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Modules that depend_on $name. Empty list if none (or if $name unknown).
     *
     * @return list<string>
     */
    public function dependents(string $name): array
    {
        $out = [];
        foreach ($this->modules as $other => $manifest) {
            if (in_array($name, $manifest->dependsOn(), true)) {
                $out[] = $other;
            }
        }
        return $out;
    }

    /**
     * Modules that $name depends_on. Empty list if $name unknown.
     *
     * @return list<string>
     */
    public function dependencies(string $name): array
    {
        if (!isset($this->modules[$name])) {
            return [];
        }
        return $this->modules[$name]->dependsOn();
    }

    /**
     * Return the manifest whose route prefix is the longest match for $path,
     * or null if no module claims this path.
     */
    public function findOwnerOfPath(string $path): ?ModuleManifest
    {
        $bestOwner = null;
        $bestLen = -1;
        foreach ($this->modules as $manifest) {
            $match = $manifest->routePrefixes()->longestMatch($path);
            if ($match !== null && strlen($match) > $bestLen) {
                $bestLen = strlen($match);
                $bestOwner = $manifest;
            }
        }
        return $bestOwner;
    }

    /**
     * Read a config/modules.php-shaped file and overlay each entry onto the
     * already-discovered manifest of the same name.
     *
     * Throws if:
     *   - catalog references a name we did not discover
     *   - shape is invalid (sidebar must be ?SidebarEntry, route_prefixes must
     *     be RoutePrefixes, depends_on must be list<string>, etc.)
     *   - is_core=true with default_available=false (nonsensical combination)
     */
    private function mergePlatformMetadata(string $path): void
    {
        /** @var mixed $catalog */
        $catalog = require $path;
        if (!is_array($catalog)) {
            throw new \RuntimeException("config/modules.php must return an array, got " . gettype($catalog));
        }

        /** @var mixed $entry */
        foreach ($catalog as $name => $entry) {
            if (!is_string($name)) {
                throw new \RuntimeException("config/modules.php keys must be module names (string), got: " . gettype($name));
            }
            if (!isset($this->modules[$name])) {
                throw new \RuntimeException(
                    "config/modules.php references module '{$name}' which is not discoverable; remove or fix"
                );
            }
            if (!is_array($entry)) {
                throw new \RuntimeException("config/modules.php entry for '{$name}' must be an array, got " . gettype($entry));
            }
            // Narrow to array<string, mixed> for the helpers below — catalog
            // entries are author-keyed objects, not numeric lists.
            /** @var array<string, mixed> $entryNarrowed */
            $entryNarrowed = [];
            /** @var mixed $v */
            foreach ($entry as $k => $v) {
                if (!is_string($k)) {
                    throw new \RuntimeException(
                        "config/modules.php entry for '{$name}': inner keys must be strings, got " . gettype($k)
                    );
                }
                $entryNarrowed[$k] = $v;
            }

            $category        = $this->expectNullableString($entryNarrowed, 'category', $name);
            $nameKey         = $this->expectNullableString($entryNarrowed, 'name_key', $name);
            $descriptionKey  = $this->expectNullableString($entryNarrowed, 'description_key', $name);
            $isCore          = $this->expectBool($entryNarrowed, 'is_core', $name);
            $defaultAvailable = $this->expectBool($entryNarrowed, 'default_available', $name);

            if ($isCore && !$defaultAvailable) {
                throw new \RuntimeException(
                    "config/modules.php entry for '{$name}': is_core=true requires default_available=true"
                );
            }

            $sidebarRaw = $entryNarrowed['sidebar'] ?? null;
            if ($sidebarRaw !== null && !$sidebarRaw instanceof SidebarEntry) {
                throw new \RuntimeException(
                    "config/modules.php entry for '{$name}': sidebar must be ?SidebarEntry, got " .
                    (is_object($sidebarRaw) ? get_class($sidebarRaw) : gettype($sidebarRaw))
                );
            }

            $prefixesRaw = $entryNarrowed['route_prefixes'] ?? null;
            if (!$prefixesRaw instanceof RoutePrefixes) {
                throw new \RuntimeException(
                    "config/modules.php entry for '{$name}': route_prefixes must be RoutePrefixes, got " .
                    (is_object($prefixesRaw) ? get_class($prefixesRaw) : gettype($prefixesRaw))
                );
            }

            /** @var list<string> $dependsOn */
            $dependsOn = [];
            $dependsRaw = $entryNarrowed['depends_on'] ?? [];
            if (!is_array($dependsRaw) || !array_is_list($dependsRaw)) {
                throw new \RuntimeException(
                    "config/modules.php entry for '{$name}': depends_on must be a list of strings"
                );
            }
            /** @var mixed $dep */
            foreach ($dependsRaw as $dep) {
                if (!is_string($dep)) {
                    throw new \RuntimeException(
                        "config/modules.php entry for '{$name}': depends_on entries must be strings, got " . gettype($dep)
                    );
                }
                $dependsOn[] = $dep;
            }

            $this->modules[$name] = $this->modules[$name]->withPlatformMetadata(
                category: $category,
                nameKey: $nameKey,
                descriptionKey: $descriptionKey,
                isCore: $isCore,
                defaultAvailable: $defaultAvailable,
                sidebar: $sidebarRaw,
                routePrefixes: $prefixesRaw,
                dependsOn: $dependsOn,
            );
        }
    }

    /**
     * Validate the post-merge module graph:
     *   1. every depends_on target exists
     *   2. graph is acyclic (DFS coloring)
     *   3. no two modules share an overlapping route prefix
     */
    private function validateGraph(): void
    {
        // 1. Unknown dependency check.
        foreach ($this->modules as $name => $manifest) {
            foreach ($manifest->dependsOn() as $dep) {
                if (!isset($this->modules[$dep])) {
                    throw new \RuntimeException(
                        "module '{$name}' depends_on '{$dep}' which is not in this deployment"
                    );
                }
            }
        }

        // 2. Cycle detection (DFS coloring: 0=white, 1=grey, 2=black).
        /** @var array<string, int> $color */
        $color = [];
        foreach ($this->modules as $name => $_) {
            $color[$name] = 0;
        }
        foreach (array_keys($this->modules) as $start) {
            if ($color[$start] === 0) {
                $this->dfsDetectCycle($start, $color);
            }
        }

        // 3. Pairwise route-prefix overlap.
        $names = array_keys($this->modules);
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $names[$i];
                $b = $names[$j];
                if ($this->modules[$a]->routePrefixes()->overlapsWith($this->modules[$b]->routePrefixes())) {
                    throw new \RuntimeException(
                        "modules '{$a}' and '{$b}' have overlapping route_prefixes"
                    );
                }
            }
        }
    }

    /**
     * @param array<string, int> $color  reference; mutated as DFS proceeds.
     */
    private function dfsDetectCycle(string $name, array &$color): void
    {
        $color[$name] = 1; // grey
        foreach ($this->modules[$name]->dependsOn() as $dep) {
            if (($color[$dep] ?? 0) === 1) {
                throw new \RuntimeException("dependency cycle through module '{$name}'");
            }
            if (($color[$dep] ?? 0) === 0) {
                $this->dfsDetectCycle($dep, $color);
            }
        }
        $color[$name] = 2; // black
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function expectNullableString(array $entry, string $key, string $moduleName): ?string
    {
        if (!array_key_exists($key, $entry)) {
            return null;
        }
        $val = $entry[$key];
        if ($val === null) {
            return null;
        }
        if (!is_string($val)) {
            throw new \RuntimeException(
                "config/modules.php entry for '{$moduleName}': '{$key}' must be ?string, got " . gettype($val)
            );
        }
        return $val;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function expectBool(array $entry, string $key, string $moduleName): bool
    {
        if (!array_key_exists($key, $entry)) {
            throw new \RuntimeException(
                "config/modules.php entry for '{$moduleName}': '{$key}' is required (bool)"
            );
        }
        $val = $entry[$key];
        if (!is_bool($val)) {
            throw new \RuntimeException(
                "config/modules.php entry for '{$moduleName}': '{$key}' must be bool, got " . gettype($val)
            );
        }
        return $val;
    }
}
