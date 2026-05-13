<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use PHPUnit\Framework\TestCase;

final class CommandInterfaceContractTest extends TestCase
{
    public function test_command_interface_has_execute_method(): void
    {
        $reflect = new \ReflectionClass(CommandInterface::class);
        $this->assertTrue($reflect->isInterface());
        $this->assertTrue($reflect->hasMethod('execute'));
        $this->assertTrue($reflect->hasMethod('name'));

        $execute = $reflect->getMethod('execute');
        $this->assertSame('int', (string) $execute->getReturnType());
        $this->assertCount(1, $execute->getParameters());
        $this->assertSame('args', $execute->getParameters()[0]->getName());

        $name = $reflect->getMethod('name');
        $this->assertSame('string', (string) $name->getReturnType());
    }
}
