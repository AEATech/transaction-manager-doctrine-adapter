<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter\StatementExecutor;

use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\BindingInfoResolver;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Statement as DbalStatement;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatementExecutor::class)]
class StatementExecutorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Connection&m\MockInterface $connection;
    private BindingInfoResolver&m\MockInterface $resolver;
    private StatementExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = m::mock(Connection::class);
        $this->resolver = m::mock(BindingInfoResolver::class);

        $this->executor = new StatementExecutor($this->resolver);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Test]
    public function executesWithPositionalParamsAndDefaultTypes(): void
    {
        $driverStmt = m::mock(DriverStatement::class);
        $driverResult = m::mock(DriverResult::class);
        $dbalStmt = m::mock(DbalStatement::class);

        $dbalStmt->shouldReceive('getWrappedStatement')->andReturn($driverStmt);

        // Expect bindValue with 1-indexed positions and default STRING type
        $driverStmt->shouldReceive('bindValue')->once()->with(1, 'a', ParameterType::STRING);
        $driverStmt->shouldReceive('bindValue')->once()->with(2, 123, ParameterType::STRING);

        $driverStmt->shouldReceive('execute')->once()->andReturn($driverResult);
        $driverResult->shouldReceive('rowCount')->once()->andReturn(2);

        $affected = $this->executor->execute($this->connection, $dbalStmt, ['a', 123], []);

        self::assertSame(2, $affected);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Test]
    public function executesWithPositionalParamsAndResolvedTypes(): void
    {
        $driverStmt = m::mock(DriverStatement::class);
        $driverResult = m::mock(DriverResult::class);
        $dbalStmt = m::mock(DbalStatement::class);

        $dbalStmt->shouldReceive('getWrappedStatement')->andReturn($driverStmt);

        $this->resolver->shouldReceive('resolve')
            ->once()
            ->with($this->connection, 'x', 'integer')
            ->andReturn(['X', ParameterType::INTEGER]);

        $this->resolver->shouldReceive('resolve')
            ->once()
            ->with($this->connection, 'y', ParameterType::BOOLEAN)
            ->andReturn(['Y', ParameterType::BOOLEAN]);

        $driverStmt->shouldReceive('bindValue')->once()->with(1, 'X', ParameterType::INTEGER);
        $driverStmt->shouldReceive('bindValue')->once()->with(2, 'Y', ParameterType::BOOLEAN);

        $driverStmt->shouldReceive('execute')->once()->andReturn($driverResult);
        $driverResult->shouldReceive('rowCount')->once()->andReturn(3);

        $params = ['x', 'y'];
        $types = ['integer', ParameterType::BOOLEAN];

        $affected = $this->executor->execute($this->connection, $dbalStmt, $params, $types);

        self::assertSame(3, $affected);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Test]
    public function executesWithNamedParamsAndDefaultTypes(): void
    {
        $driverStmt = m::mock(DriverStatement::class);
        $driverResult = m::mock(DriverResult::class);
        $dbalStmt = m::mock(DbalStatement::class);

        $dbalStmt->shouldReceive('getWrappedStatement')->andReturn($driverStmt);

        $driverStmt->shouldReceive('bindValue')->once()->with(':a', 'x', ParameterType::STRING);
        $driverStmt->shouldReceive('bindValue')->once()->with(':b', 'y', ParameterType::STRING);

        $driverStmt->shouldReceive('execute')->once()->andReturn($driverResult);
        $driverResult->shouldReceive('rowCount')->once()->andReturn(5);

        $params = [':a' => 'x', ':b' => 'y'];
        $types = [];

        $affected = $this->executor->execute($this->connection, $dbalStmt, $params, $types);

        self::assertSame(5, $affected);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Test]
    public function executesWithNamedParamsAndResolvedTypes(): void
    {
        $driverStmt = m::mock(DriverStatement::class);
        $driverResult = m::mock(DriverResult::class);
        $dbalStmt = m::mock(DbalStatement::class);

        $dbalStmt->shouldReceive('getWrappedStatement')->andReturn($driverStmt);

        // Will resolve for provided types
        $this->resolver->shouldReceive('resolve')
            ->once()
            ->with($this->connection, 'x', 'integer')
            ->andReturn(['x', ParameterType::INTEGER]);

        $this->resolver->shouldReceive('resolve')
            ->once()
            ->with($this->connection, 'y', ParameterType::BOOLEAN)
            ->andReturn(['y', ParameterType::BOOLEAN]);

        $driverStmt->shouldReceive('bindValue')->once()->with(':a', 'x', ParameterType::INTEGER);
        $driverStmt->shouldReceive('bindValue')->once()->with(':b', 'y', ParameterType::BOOLEAN);

        $driverStmt->shouldReceive('execute')->once()->andReturn($driverResult);
        $driverResult->shouldReceive('rowCount')->once()->andReturn(1);

        $params = [':a' => 'x', ':b' => 'y'];
        $types = [':a' => 'integer', ':b' => ParameterType::BOOLEAN];
        $affected = $this->executor->execute($this->connection, $dbalStmt, $params, $types);

        self::assertSame(1, $affected);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Test]
    public function executesWithPositionalParamsAndSparseTypesMapping(): void
    {
        $driverStmt = m::mock(DriverStatement::class);
        $driverResult = m::mock(DriverResult::class);
        $dbalStmt = m::mock(DbalStatement::class);

        $dbalStmt->shouldReceive('getWrappedStatement')->andReturn($driverStmt);

        // types[1] refers to the second parameter, the first one defaults to STRING
        $this->resolver->shouldReceive('resolve')
            ->once()
            ->with($this->connection, 'y', 'integer')
            ->andReturn(['Y', ParameterType::INTEGER]);

        $driverStmt->shouldReceive('bindValue')->once()->with(1, 'x', ParameterType::STRING);
        $driverStmt->shouldReceive('bindValue')->once()->with(2, 'Y', ParameterType::INTEGER);

        $driverStmt->shouldReceive('execute')->once()->andReturn($driverResult);
        $driverResult->shouldReceive('rowCount')->once()->andReturn(2);

        $params = ['x', 'y'];
        $types = [1 => 'integer'];

        $affected = $this->executor->execute($this->connection, $dbalStmt, $params, $types);

        self::assertSame(2, $affected);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Test]
    public function executesWithNullValueAndPdoParamNull(): void
    {
        $driverStmt = m::mock(DriverStatement::class);
        $driverResult = m::mock(DriverResult::class);
        $dbalStmt = m::mock(DbalStatement::class);

        $dbalStmt->shouldReceive('getWrappedStatement')->andReturn($driverStmt);

        $this->resolver->shouldReceive('resolve')
            ->once()
            ->with($this->connection, null, PDO::PARAM_NULL)
            ->andReturn([null, ParameterType::NULL]);

        $driverStmt->shouldReceive('bindValue')->once()->with(1, null, ParameterType::NULL);

        $driverStmt->shouldReceive('execute')->once()->andReturn($driverResult);
        $driverResult->shouldReceive('rowCount')->once()->andReturn(1);

        $affected = $this->executor->execute($this->connection, $dbalStmt, [null], [PDO::PARAM_NULL]);

        self::assertSame(1, $affected);
    }
}
