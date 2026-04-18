<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Container;

use Closure;
use RuntimeException;

final class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = function (Container $c) use ($abstract, $factory): mixed {
            if (!array_key_exists($abstract, $this->instances)) {
                $this->instances[$abstract] = $factory($c);
            }
            return $this->instances[$abstract];
        };
    }

    public function make(string $abstract): mixed
    {
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }
        throw new RuntimeException("No binding registered for: {$abstract}");
    }
}
