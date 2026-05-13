<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Console;

final class ConsoleKernel
{
    public function __construct(private readonly CommandRegistry $registry) {}

    /**
     * Dispatch. argv format: [command-name, --opt=value, --flag, ...]
     *
     * @param list<string> $argv
     */
    public function handle(array $argv): int
    {
        if (count($argv) === 0) {
            fwrite(STDERR, "Usage: bin/console <command> [--option=value]\n");
            return 1;
        }

        $name = (string) $argv[0];
        if (!$this->registry->has($name)) {
            fwrite(STDERR, "Unknown command: {$name}\n");
            return 2;
        }

        $args = $this->parseArgs(array_slice($argv, 1));
        return $this->registry->get($name)->execute($args);
    }

    /**
     * @param list<string> $rest
     * @return array<string,string|bool>
     */
    private function parseArgs(array $rest): array
    {
        $out = [];
        foreach ($rest as $token) {
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $kv = substr($token, 2);
            if (str_contains($kv, '=')) {
                [$k, $v] = explode('=', $kv, 2);
                $out[$k] = $v;
            } else {
                $out[$kv] = true;
            }
        }
        return $out;
    }
}
