<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

final class ModuleManifest
{
    private function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $description,
        private readonly string $namespace,
        private readonly string $absoluteSrcPath,
        private readonly string $absoluteBindingsPath,
        private readonly string $absoluteTestBindingsPath,
        private readonly string $absoluteRoutesPath,
        private readonly string $absoluteMigrationsPath,
        private readonly ?string $absolutePublicPagesPath,
        private readonly ?string $absoluteBackstagePagesPath,
        private readonly ?string $absoluteAssetsPath,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $moduleDir): self
    {
        foreach (['name', 'version', 'namespace', 'src_path', 'bindings', 'routes', 'migrations_path'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                throw new ManifestValidationException("module.json missing or empty required field: {$required}");
            }
        }

        /** @var string $name */
        $name = $data['name'];
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            throw new ManifestValidationException("module.json: name must be kebab-case (lowercase letters, digits, hyphens; must start with a letter): got '{$name}'");
        }

        /** @var string $namespace */
        $namespace = $data['namespace'];
        if (!str_ends_with($namespace, '\\')) {
            throw new ManifestValidationException("module.json: namespace must have trailing backslash: got '{$namespace}'");
        }

        $base = rtrim($moduleDir, '/\\');
        $abs = static fn(string $rel): string => $base . '/' . ltrim($rel, '/');

        /** @var string $bindingsRel */
        $bindingsRel = $data['bindings'];
        $bindingsPath = $abs($bindingsRel);
        // Test bindings live next to bindings.php with .test.php extension.
        $testBindingsPath = preg_replace('/\.php$/', '.test.php', $bindingsPath);
        if ($testBindingsPath === null) {
            throw new ManifestValidationException("module.json: bindings path must end in .php: got '{$bindingsRel}'");
        }

        /** @var array<string, mixed> $frontend */
        $frontend = isset($data['frontend']) && is_array($data['frontend']) ? $data['frontend'] : [];

        /** @var string $version */
        $version = $data['version'];
        /** @var string $srcPath */
        $srcPath = $data['src_path'];
        /** @var string $routesRel */
        $routesRel = $data['routes'];
        /** @var string $migrationsRel */
        $migrationsRel = $data['migrations_path'];

        $description = isset($data['description']) && is_string($data['description']) ? $data['description'] : '';

        /** @var string|null $publicPages */
        $publicPages = isset($frontend['public_pages']) && is_string($frontend['public_pages']) ? $frontend['public_pages'] : null;
        /** @var string|null $backstagePages */
        $backstagePages = isset($frontend['backstage_pages']) && is_string($frontend['backstage_pages']) ? $frontend['backstage_pages'] : null;
        /** @var string|null $assets */
        $assets = isset($frontend['assets']) && is_string($frontend['assets']) ? $frontend['assets'] : null;

        return new self(
            name: $name,
            version: $version,
            description: $description,
            namespace: $namespace,
            absoluteSrcPath: $abs($srcPath),
            absoluteBindingsPath: $bindingsPath,
            absoluteTestBindingsPath: $testBindingsPath,
            absoluteRoutesPath: $abs($routesRel),
            absoluteMigrationsPath: $abs($migrationsRel),
            absolutePublicPagesPath: $publicPages !== null ? $abs($publicPages) : null,
            absoluteBackstagePagesPath: $backstagePages !== null ? $abs($backstagePages) : null,
            absoluteAssetsPath: $assets !== null ? $abs($assets) : null,
        );
    }

    public function name(): string { return $this->name; }
    public function version(): string { return $this->version; }
    public function description(): string { return $this->description; }
    public function namespace(): string { return $this->namespace; }
    public function absoluteSrcPath(): string { return $this->absoluteSrcPath; }
    public function absoluteBindingsPath(): string { return $this->absoluteBindingsPath; }
    public function absoluteTestBindingsPath(): string { return $this->absoluteTestBindingsPath; }
    public function absoluteRoutesPath(): string { return $this->absoluteRoutesPath; }
    public function absoluteMigrationsPath(): string { return $this->absoluteMigrationsPath; }
    public function absolutePublicPagesPath(): ?string { return $this->absolutePublicPagesPath; }
    public function absoluteBackstagePagesPath(): ?string { return $this->absoluteBackstagePagesPath; }
    public function absoluteAssetsPath(): ?string { return $this->absoluteAssetsPath; }
}
