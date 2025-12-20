<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\AbstractConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\DbalPostgresConnectionAdapter;
use AEATech\TransactionManager\IsolationLevel;
use AEATech\TransactionManager\TxOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

#[CoversClass(DbalPostgresConnectionAdapter::class)]
class DbalPostgresConnectionAdapterTest extends ConnectionAdapterTestCase
{
    protected function buildConnectionAdapter(): AbstractConnectionAdapter
    {
        return new DbalPostgresConnectionAdapter($this->connection, $this->statementExecutor);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('isolationLevelDataProvider')]
    public function beginTransactionWithOptions(?IsolationLevel $isolationLevel): void
    {
        $this->connection->shouldReceive('isTransactionActive')->andReturn(false);

        $opt = new TxOptions(
            isolationLevel: $isolationLevel
        );

        $this->connection->shouldReceive('beginTransaction')
            ->ordered()
            ->once();

        if (null !== $isolationLevel) {
            $this->connection->shouldReceive('executeStatement')
                ->ordered()
                ->once()
                ->with('SET TRANSACTION ISOLATION LEVEL ' . $opt->isolationLevel->value);
        }

        $this->connectionAdapter->beginTransactionWithOptions($opt);
    }
}
