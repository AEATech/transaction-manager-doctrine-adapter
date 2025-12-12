<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\Query;
use Doctrine\DBAL\Connection;

abstract class AbstractConnectionAdapter implements ConnectionInterface
{
    public function __construct(
        protected readonly Connection $connection
    ) {
    }

    public function executeQuery(Query $query): int
    {
        return $this->connection->executeStatement($query->sql, $query->params, $query->types);
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
