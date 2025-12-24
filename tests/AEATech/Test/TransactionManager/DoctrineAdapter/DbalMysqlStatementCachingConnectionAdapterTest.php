<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\AbstractStatementCachingConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\DbalMysqlStatementCachingConnectionAdapter;
use AEATech\TransactionManager\IsolationLevel;
use AEATech\TransactionManager\TxOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

#[CoversClass(DbalMysqlStatementCachingConnectionAdapter::class)]
class DbalMysqlStatementCachingConnectionAdapterTest extends StatementCachingConnectionAdapterTestCase
{
    protected function buildConnectionAdapter(): AbstractStatementCachingConnectionAdapter
    {
        return new DbalMysqlStatementCachingConnectionAdapter(
            $this->connection,
            $this->statementExecutor,
            $this->statementCacheKeyBuilder,
            $this->perTransactionCache,
            $this->perConnectionCache,
        );
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('isolationLevelDataProvider')]
    public function beginTransactionWithOptions(?IsolationLevel $isolationLevel): void
    {
        $this->connection->shouldReceive('isTransactionActive')->andReturn(false);

        $this->perTransactionCache->shouldReceive('clear')->once();

        $opt = new TxOptions(
            isolationLevel: $isolationLevel
        );

        if (null !== $isolationLevel) {
            $this->connection->shouldReceive('executeStatement')
                ->ordered()
                ->once()
                ->with('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel->value);
        }

        $this->connection->shouldReceive('beginTransaction')
            ->ordered()
            ->once();

        $this->connectionAdapter->beginTransactionWithOptions($opt);
    }
}
