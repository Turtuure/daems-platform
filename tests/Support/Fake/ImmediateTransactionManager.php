<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Shared\TransactionManagerInterface;

final class ImmediateTransactionManager implements TransactionManagerInterface
{
    public function run(callable $fn): mixed
    {
        return $fn();
    }
}
