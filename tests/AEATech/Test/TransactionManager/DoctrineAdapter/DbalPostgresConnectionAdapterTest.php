<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\AbstractConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\DbalPostgresConnectionAdapter;
use AEATech\TransactionManager\TxOptions;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class DbalPostgresConnectionAdapterTest extends ConnectionAdapterTestCase
{
    protected function buildConnectionAdapter(): AbstractConnectionAdapter
    {
        return new DbalPostgresConnectionAdapter($this->connection);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function beginTransactionWithOptions(): void
    {
        $this->connection->shouldReceive('isTransactionActive')->andReturn(false);

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
