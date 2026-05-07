<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\ManifestValidationException;
use Daems\Infrastructure\Module\ModuleManifest;
use Daems\Infrastructure\Module\RoutePrefixes;
use Daems\Infrastructure\Module\SidebarEntry;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    public function test_parses_complete_manifest(): void
    {
        $data = [
            'name' => 'insights',
            'version' => '1.0.0',
            'description' => 'Articles + scheduled publishing',
            'namespace' => 'DaemsModule\\Insights\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
            'frontend' => [
                'public_pages' => 'frontend/public/',
                'backstage_pages' => 'frontend/backstage/',
                'assets' => 'frontend/assets/',
            ],
            'requires' => ['core' => '>=1.0.0'],
        ];
        $m = ModuleManifest::fromArray($data, '/path/to/modules/insights');
        self::assertSame('insights', $m->name());
        self::assertSame('1.0.0', $m->version());
        self::assertSame('DaemsModule\\Insights\\', $m->namespace());
        self::assertSame('/path/to/modules/insights/backend/src/', $m->absoluteSrcPath());
        self::assertSame('/path/to/modules/insights/backend/bindings.php', $m->absoluteBindingsPath());
        self::assertSame('/path/to/modules/insights/backend/bindings.test.php', $m->absoluteTestBindingsPath());
        self::assertSame('/path/to/modules/insights/backend/routes.php', $m->absoluteRoutesPath());
        self::assertSame('/path/to/modules/insights/backend/migrations/', $m->absoluteMigrationsPath());
    }

    public function test_throws_on_missing_required_field(): void
    {
        $this->expectException(ManifestValidationException::class);
        $this->expectExceptionMessageMatches('/missing.*name/i');
        ModuleManifest::fromArray(['version' => '1.0.0'], '/path');
    }

    public function test_throws_on_invalid_name_pattern(): void
    {
        $this->expectException(ManifestValidationException::class);
        ModuleManifest::fromArray([
            'name' => 'Insights With Spaces',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
        ], '/path');
    }

    public function test_namespace_must_have_trailing_backslash(): void
    {
        $this->expectException(ManifestValidationException::class);
        $this->expectExceptionMessageMatches('/namespace.*trailing/i');
        ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
        ], '/path');
    }

    public function test_normalizes_windows_backslashes_in_paths(): void
    {
        $m = ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend\\src\\',
            'bindings' => 'backend\\bindings.php',
            'routes' => 'backend\\routes.php',
            'migrations_path' => 'backend\\migrations\\',
        ], '/path/to/x');
        // Backslashes in JSON-supplied paths must collapse to forward slashes
        // before concatenation — no mixed-separator output.
        self::assertSame('/path/to/x/backend/src/', $m->absoluteSrcPath());
        self::assertSame('/path/to/x/backend/bindings.php', $m->absoluteBindingsPath());
        self::assertSame('/path/to/x/backend/migrations/', $m->absoluteMigrationsPath());
    }

    public function test_throws_on_empty_optional_frontend_field(): void
    {
        $this->expectException(ManifestValidationException::class);
        $this->expectExceptionMessageMatches('/frontend.public_pages.*empty/i');
        ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
            'frontend' => ['public_pages' => ''],
        ], '/path');
    }

    public function test_parses_requires_map(): void
    {
        $m = ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
            'requires' => ['core' => '>=1.0.0', 'forum' => '^1.2'],
        ], '/path');
        self::assertSame(['core' => '>=1.0.0', 'forum' => '^1.2'], $m->requires());
    }

    public function test_requires_defaults_to_empty_array_when_absent(): void
    {
        $m = ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
        ], '/path');
        self::assertSame([], $m->requires());
    }

    public function test_throws_on_non_kebab_requires_key(): void
    {
        $this->expectException(ManifestValidationException::class);
        $this->expectExceptionMessageMatches('/requires.*kebab-case/i');
        ModuleManifest::fromArray([
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'b.php',
            'routes' => 'r.php',
            'migrations_path' => 'm/',
            'requires' => ['Core With Spaces' => '>=1.0.0'],
        ], '/path');
    }

    public function test_platform_metadata_defaults_to_safe_values(): void
    {
        $m = ModuleManifest::fromArray($this->validManifestData(), '/path/to/x');
        self::assertNull($m->category());
        self::assertNull($m->nameKey());
        self::assertNull($m->descriptionKey());
        self::assertFalse($m->isCore());
        self::assertFalse($m->defaultAvailable());
        self::assertNull($m->sidebar());
        self::assertSame([], $m->routePrefixes()->backstagePrefixes());
        self::assertSame([], $m->routePrefixes()->apiPrefixes());
        self::assertSame([], $m->dependsOn());
    }

    public function test_with_platform_metadata_returns_new_instance_with_populated_fields(): void
    {
        $base = ModuleManifest::fromArray($this->validManifestData(), '/path/to/x');
        $sidebar = new SidebarEntry(group: 'content', order: 20, icon: 'calendar', href: '/backstage/x');
        $prefixes = new RoutePrefixes(backstage: ['/backstage/x'], api: ['/api/v1/x']);

        $next = $base->withPlatformMetadata(
            category: 'content',
            nameKey: 'modules.x.name',
            descriptionKey: 'modules.x.description',
            isCore: false,
            defaultAvailable: true,
            sidebar: $sidebar,
            routePrefixes: $prefixes,
            dependsOn: ['core'],
        );

        // Original untouched.
        self::assertNull($base->category());
        self::assertFalse($base->defaultAvailable());
        self::assertNull($base->sidebar());

        // New instance has populated fields.
        self::assertSame('content', $next->category());
        self::assertSame('modules.x.name', $next->nameKey());
        self::assertSame('modules.x.description', $next->descriptionKey());
        self::assertFalse($next->isCore());
        self::assertTrue($next->defaultAvailable());
        self::assertSame($sidebar, $next->sidebar());
        self::assertSame($prefixes, $next->routePrefixes());
        self::assertSame(['core'], $next->dependsOn());

        // module.json fields preserved across the copy.
        self::assertSame($base->name(), $next->name());
        self::assertSame($base->version(), $next->version());
        self::assertSame($base->namespace(), $next->namespace());
        self::assertSame($base->absoluteSrcPath(), $next->absoluteSrcPath());
        self::assertSame($base->absoluteBindingsPath(), $next->absoluteBindingsPath());
        self::assertSame($base->absoluteRoutesPath(), $next->absoluteRoutesPath());
        self::assertSame($base->absoluteMigrationsPath(), $next->absoluteMigrationsPath());
        self::assertSame($base->requires(), $next->requires());
    }

    /** @return array<string, mixed> */
    private function validManifestData(): array
    {
        return [
            'name' => 'x',
            'version' => '1.0.0',
            'namespace' => 'DaemsModule\\X\\',
            'src_path' => 'backend/src/',
            'bindings' => 'backend/bindings.php',
            'routes' => 'backend/routes.php',
            'migrations_path' => 'backend/migrations/',
        ];
    }
}
