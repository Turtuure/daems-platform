<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

final class CommandRegistry
{
    /** @var array<string,CommandInterface> */
    private array $commands = [];

    public function register(CommandInterface $cmd): void
    {
        $this->commands[$cmd->name()] = $cmd;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): CommandInterface
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException("No command registered: {$name}");
        }
        return $this->commands[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->commands);
    }
}
