<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use AEATech\TransactionManager\Query;
use Doctrine\DBAL\Connection;

abstract class AbstractConnectionAdapter implements ConnectionInterface
{
    public function __construct(
        protected readonly Connection $connection,
        private readonly StatementExecutor $statementExecutor
    ) {
    }

    public function executeQuery(Query $query): int
    {
        // Fast-path: no params => let DBAL do driver->exec().
        if ([] === $query->params) {
            return (int)$this->connection->executeStatement($query->sql);
        }

        return $this->statementExecutor->execute(
            $this->connection,
            $this->connection->prepare($query->sql),
            $query->params,
            $query->types
        );
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
