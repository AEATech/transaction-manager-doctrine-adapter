<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter\StatementCache;

use AEATech\TransactionManager\DoctrineAdapter\StatementCache\SqlAndParamCountCacheKeyBuilder;
use AEATech\TransactionManager\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlAndParamCountCacheKeyBuilder::class)]
class SqlAndParamCountCacheKeyBuilderTest extends TestCase
{
    private SqlAndParamCountCacheKeyBuilder $cacheKeyBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheKeyBuilder = new SqlAndParamCountCacheKeyBuilder();
    }

    #[Test]
    public function buildProducesHashAndParamShape(): void
    {
        $q = new Query('update test set b=1 where a in(?, ?)', ['a', 'b']);

        $key = $this->cacheKeyBuilder->build($q);

        self::assertStringContainsString('|p:2', $key);
        self::assertSame(hash('sha256', $q->sql) . '|p:2', $key);
    }

    #[Test]
    public function buildIsSensitiveOnlyToSqlAndParamCount(): void
    {
        $q1 = new Query('update test set b=1 where a in(?, ?)', ['x', 'y']);
        $q2 = new Query('update test set b=1 where a in(?, ?)', ['y', 'x']); // same count, different values

        self::assertSame($this->cacheKeyBuilder->build($q1), $this->cacheKeyBuilder->build($q2));
    }

    #[Test]
    public function buildIgnoresNamedParamsOrderAndNames(): void
    {
        $sql = 'UPDATE t SET a = :a WHERE id = :id';

        $q1 = new Query($sql, [':a' => 1, ':id' => 10]);
        $q2 = new Query($sql, [':id' => 10, ':a' => 1]);

        self::assertSame($this->cacheKeyBuilder->build($q1), $this->cacheKeyBuilder->build($q2));
    }
}
