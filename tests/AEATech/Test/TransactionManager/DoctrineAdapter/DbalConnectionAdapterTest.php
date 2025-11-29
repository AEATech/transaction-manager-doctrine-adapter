<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\DbalConnectionAdapter;
use AEATech\TransactionManager\IsolationLevel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

class DbalConnectionAdapterTest extends TestCase
{
    private Connection&m\MockInterface $connection;
    private DbalConnectionAdapter $connectionAdapter;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = m::mock(Connection::class);
        $this->connectionAdapter = new DbalConnectionAdapter($this->connection);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function beginTransaction(): void
    {
        $this->connection->shouldReceive('beginTransaction')->once();

        $this->connectionAdapter->beginTransaction();
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[DataProvider('setTransactionIsolationDataProvider')]
    public function setTransactionIsolationLevel(IsolationLevel $isolationLevel, mixed $expected): void
    {
        $this->connection->shouldReceive('setTransactionIsolation')
            ->once()
            ->with($expected);

        $this->connectionAdapter->setTransactionIsolationLevel($isolationLevel);
    }

    public static function setTransactionIsolationDataProvider(): array
    {
        return [
            [
                'isolationLevel' => IsolationLevel::ReadCommitted,
                'expected' => TransactionIsolationLevel::READ_COMMITTED,
            ],
            [
                'isolationLevel' => IsolationLevel::RepeatableRead,
                'expected' => TransactionIsolationLevel::REPEATABLE_READ,
            ],
            [
                'isolationLevel' => IsolationLevel::Serializable,
                'expected' => TransactionIsolationLevel::SERIALIZABLE,
            ],
            [
                'isolationLevel' => IsolationLevel::ReadUncommitted,
                'expected' => TransactionIsolationLevel::READ_UNCOMMITTED,
            ],
        ];
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function executeStatement(): void
    {
        $sql = '...';
        $params = ['...'];
        $types = ['...'];

        $affectedRows = 1;

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->with($sql, $params, $types)
            ->andReturn($affectedRows);

        self::assertSame($affectedRows, $this->connectionAdapter->executeStatement($sql, $params, $types));
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

    protected function tearDown(): void
    {
        parent::tearDown();

        m::close();
    }
}
