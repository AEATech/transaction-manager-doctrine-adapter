<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\AbstractStatementCachingConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\StatementCacheInterface;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\StatementCacheKeyBuilderInterface;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use AEATech\TransactionManager\IsolationLevel;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\StatementReusePolicy;
use AEATech\TransactionManager\TxOptions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use LogicException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mockery as m;
use Throwable;

abstract class StatementCachingConnectionAdapterTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected Connection&m\MockInterface $connection;
    protected StatementExecutor&m\MockInterface $statementExecutor;
    protected StatementCacheKeyBuilderInterface&m\MockInterface $statementCacheKeyBuilder;
    protected StatementCacheInterface&m\MockInterface $perTransactionCache;
    protected StatementCacheInterface&m\MockInterface $perConnectionCache;

    protected AbstractStatementCachingConnectionAdapter $connectionAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = m::mock(Connection::class);
        $this->statementExecutor = m::mock(StatementExecutor::class);
        $this->statementCacheKeyBuilder = m::mock(StatementCacheKeyBuilderInterface::class);
        $this->perTransactionCache = m::mock(StatementCacheInterface::class);
        $this->perConnectionCache = m::mock(StatementCacheInterface::class);

        $this->connectionAdapter = $this->buildConnectionAdapter();
    }

    abstract protected function buildConnectionAdapter(): AbstractStatementCachingConnectionAdapter;

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
        $sql = 'sql...';
        $params = [];
        $types = [];

        $affectedRows = 10;

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
    public function executeQueryWithParamsWithoutCache(): void
    {
        $sql = 'sql...';
        $params = ['p...'];
        $types = ['t...'];

        $affectedRows = 10;

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

        $query = new Query(
            sql: $sql,
            params: $params,
            types: $types,
            statementReusePolicy: StatementReusePolicy::None
        );

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery($query));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeQueryWithParamsWithPerTransactionCacheWithoutCachedStmt(): void
    {
        $sql = 'sql...';
        $params = ['p...'];
        $types = ['t...'];

        $query = new Query(
            sql: $sql,
            params: $params,
            types: $types,
            statementReusePolicy: StatementReusePolicy::PerTransaction
        );

        $affectedRows = 10;

        $cacheKey = 'c....';

        $this->statementCacheKeyBuilder->shouldReceive('build')
            ->once()
            ->with($query)
            ->andReturn($cacheKey);

        $this->perTransactionCache->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn(null);

        $stmt = m::mock(Statement::class);

        $this->connection->shouldReceive('prepare')
            ->once()
            ->with($sql)
            ->andReturn($stmt);

        $this->perTransactionCache->shouldReceive('set')
            ->once()
            ->with($cacheKey, $stmt);

        $this->statementExecutor->shouldReceive('execute')
            ->once()
            ->with(
                $this->connection,
                $stmt,
                $params,
                $types
            )
            ->andReturn($affectedRows);

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery($query));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeQueryWithParamsWithPerTransactionCacheWithCachedStmt(): void
    {
        $sql = 'sql...';
        $params = ['p...'];
        $types = ['t...'];

        $query = new Query(
            sql: $sql,
            params: $params,
            types: $types,
            statementReusePolicy: StatementReusePolicy::PerTransaction
        );

        $affectedRows = 10;

        $cacheKey = 'c....';

        $this->statementCacheKeyBuilder->shouldReceive('build')
            ->once()
            ->with($query)
            ->andReturn($cacheKey);

        $stmt = m::mock(Statement::class);

        $this->perTransactionCache->shouldReceive('get')
            ->once()
            ->with($cacheKey)
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

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery($query));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeQueryWithParamsWithPerConnectionCacheWithoutCachedStmt(): void
    {
        $sql = 'sql...';
        $params = ['p...'];
        $types = ['t...'];

        $query = new Query(
            sql: $sql,
            params: $params,
            types: $types,
            statementReusePolicy: StatementReusePolicy::PerConnection
        );

        $affectedRows = 10;

        $cacheKey = 'c....';

        $this->statementCacheKeyBuilder->shouldReceive('build')
            ->once()
            ->with($query)
            ->andReturn($cacheKey);

        $this->perConnectionCache->shouldReceive('get')
            ->once()
            ->with($cacheKey)
            ->andReturn(null);

        $stmt = m::mock(Statement::class);

        $this->connection->shouldReceive('prepare')
            ->once()
            ->with($sql)
            ->andReturn($stmt);

        $this->perConnectionCache->shouldReceive('set')
            ->once()
            ->with($cacheKey, $stmt);

        $this->statementExecutor->shouldReceive('execute')
            ->once()
            ->with(
                $this->connection,
                $stmt,
                $params,
                $types
            )
            ->andReturn($affectedRows);

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery($query));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeQueryWithParamsWithPerConnectionCacheWithCachedStmt(): void
    {
        $sql = 'sql...';
        $params = ['p...'];
        $types = ['t...'];

        $query = new Query(
            sql: $sql,
            params: $params,
            types: $types,
            statementReusePolicy: StatementReusePolicy::PerConnection
        );

        $affectedRows = 10;

        $cacheKey = 'c....';

        $this->statementCacheKeyBuilder->shouldReceive('build')
            ->once()
            ->with($query)
            ->andReturn($cacheKey);

        $stmt = m::mock(Statement::class);

        $this->perConnectionCache->shouldReceive('get')
            ->once()
            ->with($cacheKey)
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

        self::assertSame($affectedRows, $this->connectionAdapter->executeQuery($query));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function commit(): void
    {
        $this->connection->shouldReceive('commit')->once();

        $this->perTransactionCache->shouldReceive('clear')->once();

        $this->connectionAdapter->commit();
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function rollBack(): void
    {
        $this->connection->shouldReceive('rollBack')->once();

        $this->perTransactionCache->shouldReceive('clear')->once();

        $this->connectionAdapter->rollBack();
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function close(): void
    {
        $this->connection->shouldReceive('close')->once();

        $this->perTransactionCache->shouldReceive('clear')->once();
        $this->perConnectionCache->shouldReceive('clear')->once();

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
