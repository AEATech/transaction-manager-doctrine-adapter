<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\StatementCacheInterface;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\StatementCacheKeyBuilderInterface;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\StatementReusePolicy;
use Doctrine\DBAL\Connection;

abstract class AbstractStatementCachingConnectionAdapter implements ConnectionInterface
{
    public function __construct(
        protected readonly Connection $connection,
        private readonly StatementExecutor $statementExecutor,
        private readonly StatementCacheKeyBuilderInterface $cacheKeyBuilder,
        protected readonly StatementCacheInterface $perTransactionCache,
        private readonly StatementCacheInterface $perConnectionCache,
    ) {
    }

    public function executeQuery(Query $query): int
    {
        // Fast-path: no params => let DBAL do driver->exec().
        // No params or no caching requested -> go direct.
        if ([] === $query->params) {
            return (int)$this->connection->executeStatement($query->sql);
        }

        $cache = match ($query->statementReusePolicy) {
            StatementReusePolicy::None => null,
            StatementReusePolicy::PerTransaction => $this->perTransactionCache,
            StatementReusePolicy::PerConnection => $this->perConnectionCache,
        };

        // No statement reuse requested -> execute via prepared statement without cache.
        if (null === $cache) {
            return $this->statementExecutor->execute(
                $this->connection,
                $this->connection->prepare($query->sql),
                $query->params,
                $query->types
            );
        }

        $key = $this->cacheKeyBuilder->build($query);

        $stmt = $cache->get($key);

        if (null === $stmt) {
            $stmt = $this->connection->prepare($query->sql);
            $cache->set($key, $stmt);
        }

        return $this->statementExecutor->execute($this->connection, $stmt, $query->params, $query->types);
    }

    public function commit(): void
    {
        try {
            $this->connection->commit();
        } finally {
            // Transaction boundary -> drop PerTransaction cache
            $this->perTransactionCache->clear();
        }
    }

    public function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } finally {
            $this->perTransactionCache->clear();
        }
    }

    public function close(): void
    {
        // Connection boundary -> drop everything
        $this->perTransactionCache->clear();
        $this->perConnectionCache->clear();

        $this->connection->close();
    }
}
