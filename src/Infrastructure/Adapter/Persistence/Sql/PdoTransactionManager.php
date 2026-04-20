<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Shared\TransactionManagerInterface;
use PDO;

final class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
