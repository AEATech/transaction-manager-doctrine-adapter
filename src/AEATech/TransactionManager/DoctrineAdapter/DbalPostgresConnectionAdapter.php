<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\TxOptions;
use LogicException;

class DbalPostgresConnectionAdapter extends AbstractConnectionAdapter
{
    public function beginTransactionWithOptions(TxOptions $opt): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('Cannot begin a transaction when one is already active.');
        }

        // PostgreSQL: BEGIN and then set isolation for this transaction only
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'SET TRANSACTION ISOLATION LEVEL ' . $opt->isolationLevel->value
        );
    }
}
