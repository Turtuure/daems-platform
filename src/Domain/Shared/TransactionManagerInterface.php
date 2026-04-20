<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

interface TransactionManagerInterface
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function run(callable $fn): mixed;
}
