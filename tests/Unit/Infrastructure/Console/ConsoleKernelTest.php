<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CommandRegistry;
use Daems\Infrastructure\Console\ConsoleKernel;
use PHPUnit\Framework\TestCase;

final class ConsoleKernelTest extends TestCase
{
    public function test_dispatches_known_command(): void
    {
        $cmd = new class implements CommandInterface {
            public array $receivedArgs = [];
            public function name(): string { return 'demo:hello'; }
            public function execute(array $args): int
            {
                $this->receivedArgs = $args;
                return 0;
            }
        };
        $registry = new CommandRegistry();
        $registry->register($cmd);
        $kernel = new ConsoleKernel($registry);

        $exit = $kernel->handle(['demo:hello', '--name=World', '--verbose']);

        $this->assertSame(0, $exit);
        $this->assertSame(['name' => 'World', 'verbose' => true], $cmd->receivedArgs);
    }

    public function test_returns_2_for_unknown_command(): void
    {
        $kernel = new ConsoleKernel(new CommandRegistry());
        $this->assertSame(2, $kernel->handle(['unknown:nope']));
    }

    public function test_returns_1_when_no_command_given(): void
    {
        $kernel = new ConsoleKernel(new CommandRegistry());
        $this->assertSame(1, $kernel->handle([]));
    }
}
