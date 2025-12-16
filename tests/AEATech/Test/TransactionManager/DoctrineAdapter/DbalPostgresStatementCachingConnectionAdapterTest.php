<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\AbstractStatementCachingConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\DbalPostgresStatementCachingConnectionAdapter;
use AEATech\TransactionManager\TxOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

#[CoversClass(DbalPostgresStatementCachingConnectionAdapter::class)]
class DbalPostgresStatementCachingConnectionAdapterTest extends StatementCachingConnectionAdapterTestCase
{
    protected function buildConnectionAdapter(): AbstractStatementCachingConnectionAdapter
    {
        return new DbalPostgresStatementCachingConnectionAdapter(
            $this->connection,
            $this->statementExecutor,
            $this->statementCacheKeyBuilder,
            $this->perTransactionCache,
            $this->perConnectionCache
        );
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function beginTransactionWithOptions(): void
    {
        $this->connection->shouldReceive('isTransactionActive')->andReturn(false);

        $this->perTransactionCache->shouldReceive('clear')->once();

        $opt = new TxOptions();

        $this->connection->shouldReceive('beginTransaction')
            ->ordered()
            ->once();

        $this->connection->shouldReceive('executeStatement')
            ->ordered()
            ->once()
            ->with('SET TRANSACTION ISOLATION LEVEL ' . $opt->isolationLevel->value);

        $this->connectionAdapter->beginTransactionWithOptions($opt);
    }
}
