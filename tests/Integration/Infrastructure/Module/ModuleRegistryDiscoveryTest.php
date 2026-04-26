<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Module;

use Composer\Autoload\ClassLoader;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryDiscoveryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/dr_module_e2e_' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function test_full_discover_autoload_bind_route_cycle(): void
    {
        $modDir = $this->tmp . '/demo';
        mkdir($modDir . '/backend/src/Controller', 0777, true);
        file_put_contents($modDir . '/module.json', json_encode([
            'name' => 'demo', 'version' => '1.0.0',
            'namespace' => 'DaemsModule\\Demo\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ]));
        file_put_contents($modDir . '/backend/src/Controller/DemoController.php',
            "<?php namespace DaemsModule\\Demo\\Controller;
             class DemoController { public function ping(): string { return 'pong'; } }"
        );
        file_put_contents($modDir . '/backend/bindings.php',
            "<?php return function (\$c) {
                \$c->bind(\\DaemsModule\\Demo\\Controller\\DemoController::class,
                    fn() => new \\DaemsModule\\Demo\\Controller\\DemoController());
             };"
        );
        file_put_contents($modDir . '/backend/routes.php',
            "<?php return function (\$r, \$c) {
                \$r->get('/demo/ping', fn() => null);
             };"
        );

        $loader = new ClassLoader();
        $loader->register();

        $registry = new ModuleRegistry();
        $registry->discover($this->tmp);
        $registry->registerAutoloader($loader);

        $container = new Container();
        $router = new Router(fn(string $class) => $container->make($class));
        $registry->registerBindings($container, ModuleRegistry::PROD);
        $registry->registerRoutes($router, $container);

        // Assert: class loadable, container builds it, and ping returns 'pong'.
        $controller = $container->make(\DaemsModule\Demo\Controller\DemoController::class);
        self::assertSame('pong', $controller->ping());

        $loader->unregister();
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
