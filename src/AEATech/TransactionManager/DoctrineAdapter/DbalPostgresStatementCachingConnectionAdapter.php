<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\TxOptions;
use LogicException;

class DbalPostgresStatementCachingConnectionAdapter extends AbstractStatementCachingConnectionAdapter
{
    public function beginTransactionWithOptions(TxOptions $opt): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('Cannot begin a transaction when one is already active.');
        }

        // Per-transaction prepared statements must not cross the transaction boundary.
        $this->perTransactionCache->clear();

        // PostgreSQL: BEGIN and then set isolation for this transaction only
        $this->connection->beginTransaction();

        if (null !== $opt->isolationLevel) {
            $this->connection->executeStatement(
                'SET TRANSACTION ISOLATION LEVEL ' . $opt->isolationLevel->value
            );
        }
    }
}
