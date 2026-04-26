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
     */
    public function discover(string $modulesDir): void
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
}
