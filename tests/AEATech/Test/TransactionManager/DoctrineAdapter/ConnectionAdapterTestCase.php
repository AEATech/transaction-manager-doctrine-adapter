<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\AbstractConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use AEATech\TransactionManager\IsolationLevel;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\TxOptions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use LogicException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mockery as m;
use Throwable;

abstract class ConnectionAdapterTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected Connection&m\MockInterface $connection;
    protected StatementExecutor&m\MockInterface $statementExecutor;
    protected AbstractConnectionAdapter $connectionAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = m::mock(Connection::class);
        $this->statementExecutor = m::mock(StatementExecutor::class);
        $this->connectionAdapter = $this->buildConnectionAdapter();
    }

    abstract protected function buildConnectionAdapter(): AbstractConnectionAdapter;

    /**
     * @throws Throwable
     */
    #[Test]
    public function beginTransactionWhenTransactionIsActive(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot begin a transaction when one is already active.');

        $this->connection->shouldReceive('isTransactionActive')->andReturn(true);

        $this->connection->shouldNotReceive('beginTransaction');

        $this->connectionAdapter->beginTransactionWithOptions(new TxOptions());
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeQueryWithoutParams(): void
    {
        $sql = '...';
        $params = [];
        $types = [];

        $affectedRows = 1;

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->with($sql)
            ->andReturn($affectedRows);

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery(new Query($sql, $params, $types)));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeQueryWithParams(): void
    {
        $sql = '...';
        $params = ['p...'];
        $types = ['t...'];

        $affectedRows = 1;

        $stmt = m::mock(Statement::class);

        $this->connection->shouldReceive('prepare')
            ->once()
            ->with($sql)
            ->andReturn($stmt);

        $this->statementExecutor->shouldReceive('execute')
            ->once()
            ->with(
                $this->connection,
                $stmt,
                $params,
                $types
            )
            ->andReturn($affectedRows);

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery(new Query($sql, $params, $types)));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function commit(): void
    {
        $this->connection->shouldReceive('commit')->once();

        $this->connectionAdapter->commit();
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function rollBack(): void
    {
        $this->connection->shouldReceive('rollBack')->once();

        $this->connectionAdapter->rollBack();
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function close(): void
    {
        $this->connection->shouldReceive('close')->once();

        $this->connectionAdapter->close();
    }

    public static function isolationLevelDataProvider(): array
    {
        return [
            [
                'isolationLevel' => null,
            ],
            [
                'isolationLevel' => IsolationLevel::ReadCommitted,
            ]
        ];
    }
}
