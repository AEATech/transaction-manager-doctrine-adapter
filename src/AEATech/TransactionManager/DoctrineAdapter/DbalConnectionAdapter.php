<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\IsolationLevel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;

class DbalConnectionAdapter implements ConnectionInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function setTransactionIsolationLevel(IsolationLevel $isolationLevel): void
    {
        $this->connection->setTransactionIsolation(match ($isolationLevel) {
            IsolationLevel::ReadUncommitted => TransactionIsolationLevel::READ_UNCOMMITTED,
            IsolationLevel::ReadCommitted => TransactionIsolationLevel::READ_COMMITTED,
            IsolationLevel::RepeatableRead => TransactionIsolationLevel::REPEATABLE_READ,
            IsolationLevel::Serializable => TransactionIsolationLevel::SERIALIZABLE,
        });
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        return $this->connection->executeStatement($sql, $params, $types);
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
