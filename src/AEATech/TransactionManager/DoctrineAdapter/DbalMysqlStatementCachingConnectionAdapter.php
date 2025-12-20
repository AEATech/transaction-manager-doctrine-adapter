<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\TxOptions;
use LogicException;

class DbalMysqlStatementCachingConnectionAdapter extends AbstractStatementCachingConnectionAdapter
{
    public function beginTransactionWithOptions(TxOptions $opt): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('Cannot begin a transaction when one is already active.');
        }

        // Per-transaction prepared statements must not cross the transaction boundary.
        $this->perTransactionCache->clear();

        if (null !== $opt->isolationLevel) {
            // MySQL/MariaDB: apply isolation to the NEXT transaction only (no session leakage)
            // Allowed only when there is no active transaction.
            $this->connection->executeStatement(
                'SET TRANSACTION ISOLATION LEVEL ' . $opt->isolationLevel->value
            );
        }

        $this->connection->beginTransaction();
    }
}
