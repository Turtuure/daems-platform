<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\ManifestValidationException;
use Daems\Infrastructure\Module\ModuleManifest;
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
}
