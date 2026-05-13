<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CommandRegistry;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    public function test_register_and_lookup(): void
    {
        $registry = new CommandRegistry();
        $cmd = $this->stubCommand('foo:bar');
        $registry->register($cmd);

        $this->assertTrue($registry->has('foo:bar'));
        $this->assertSame($cmd, $registry->get('foo:bar'));
    }

    public function test_get_throws_for_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CommandRegistry())->get('does-not-exist');
    }

    public function test_names_returns_registered_in_order(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->stubCommand('a:one'));
        $registry->register($this->stubCommand('b:two'));
        $registry->register($this->stubCommand('c:three'));

        $this->assertSame(['a:one', 'b:two', 'c:three'], $registry->names());
    }

    public function test_re_register_replaces_previous(): void
    {
        $registry = new CommandRegistry();
        $first  = $this->stubCommand('x:cmd');
        $second = $this->stubCommand('x:cmd');
        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->get('x:cmd'));
    }

    private function stubCommand(string $name): CommandInterface
    {
        return new class($name) implements CommandInterface {
            public function __construct(private readonly string $n) {}
            public function name(): string { return $this->n; }
            public function execute(array $args): int { return 0; }
        };
    }
}
