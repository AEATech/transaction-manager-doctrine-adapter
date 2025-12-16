<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter\StatementCache;

use AEATech\TransactionManager\DoctrineAdapter\StatementCache\LruStatementCache;
use Doctrine\DBAL\Statement;
use InvalidArgumentException;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LruStatementCache::class)]
class LruStatementCacheTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function constructorRejectsCapacityLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LruStatementCache(0);
    }

    #[Test]
    public function getOnMissingKeyReturnsNull(): void
    {
        $cache = new LruStatementCache(2);

        self::assertNull($cache->get('missing'));
    }

    #[Test]
    public function setGetAndMoveToFront(): void
    {
        $cache = new LruStatementCache(2);

        $s1 = m::mock(Statement::class);
        $s2 = m::mock(Statement::class);

        $cache->set('k1', $s1);
        $cache->set('k2', $s2);

        // Access k1 to make it most-recently used
        self::assertSame($s1, $cache->get('k1'));

        // Insert k3 should evict least-recently used (k2)
        $s3 = m::mock(Statement::class);
        $cache->set('k3', $s3);

        self::assertNull($cache->get('k2'));
        self::assertSame($s1, $cache->get('k1'));
        self::assertSame($s3, $cache->get('k3'));
    }

    #[Test]
    public function updateExistingKeyReplacesValueAndMovesToFront(): void
    {
        $cache = new LruStatementCache(2);

        $s1a = m::mock(Statement::class);
        $s1b = m::mock(Statement::class);
        $s2 = m::mock(Statement::class);

        $cache->set('k1', $s1a);
        $cache->set('k2', $s2);

        // Update k1 -> should replace and move to the front
        $cache->set('k1', $s1b);

        // Now insert k3, LRU (k2) should be evicted as k1 was the most-recent
        $s3 = m::mock(Statement::class);
        $cache->set('k3', $s3);

        self::assertNull($cache->get('k2'));
        self::assertSame($s1b, $cache->get('k1'));
        self::assertSame($s3, $cache->get('k3'));
    }

    #[Test]
    public function clearResetsState(): void
    {
        $cache = new LruStatementCache(2);
        $s1 = m::mock(Statement::class);
        $cache->set('k1', $s1);
        self::assertSame($s1, $cache->get('k1'));

        $cache->clear();

        self::assertNull($cache->get('k1'));
        // Also ensure new insert works after clear
        $s2 = m::mock(Statement::class);
        $cache->set('k2', $s2);
        self::assertSame($s2, $cache->get('k2'));
    }

    #[Test]
    public function getOnHeadStillMaintainsOrderAndCoversHeadRemovalPath(): void
    {
        $cache = new LruStatementCache(3);

        $s1 = m::mock(Statement::class);
        $s2 = m::mock(Statement::class);

        // Build list with head=k2, tail=k1
        $cache->set('k1', $s1);
        $cache->set('k2', $s2);

        // Now k2 is head. Calling get('k2') triggers moveToFront(head),
        // which in the new implementation goes through removeNode(head)
        // and addToFront(head) — покрывая ветку prev === null в removeNode().
        self::assertSame($s2, $cache->get('k2'));

        // State should remain logically the same: k2 stays head, k1 stays tail.
        // To validate the behavior further, insert k3 and ensure LRU eviction works correctly later.
        $s3 = m::mock(Statement::class);
        $cache->set('k3', $s3); // order: k3 (head), k2, k1 (tail)

        // Access k2 to move it to the front: order becomes k2, k3, k1
        self::assertSame($s2, $cache->get('k2'));

        // Add k4; should evict k1 (tail)
        $s4 = m::mock(Statement::class);
        $cache->set('k4', $s4);

        self::assertNull($cache->get('k1'));
        self::assertSame($s2, $cache->get('k2'));
        self::assertSame($s3, $cache->get('k3'));
        self::assertSame($s4, $cache->get('k4'));
    }

    #[Test]
    public function moveMiddleNodeToFrontCoversNextNotNullBranch(): void
    {
        $cache = new LruStatementCache(3);

        $s1 = m::mock(Statement::class);
        $s2 = m::mock(Statement::class);
        $s3 = m::mock(Statement::class);

        $cache->set('k1', $s1);
        $cache->set('k2', $s2);
        $cache->set('k3', $s3);

        // Current order (MRU -> LRU): k3, k2, k1
        // Access middle (k2) to trigger removeNode with both prev and next present
        self::assertSame($s2, $cache->get('k2'));

        // Now the order should be: k2, k3, k1. Inserting k4 should evict k1.
        $s4 = m::mock(Statement::class);
        $cache->set('k4', $s4);

        self::assertNull($cache->get('k1'));
        self::assertSame($s2, $cache->get('k2'));
        self::assertSame($s3, $cache->get('k3'));
        self::assertSame($s4, $cache->get('k4'));
    }

    #[Test]
    public function repeatedSetOnSameKeyDoesNotEvictBeyondCapacity(): void
    {
        $cache = new LruStatementCache(2);

        $s1a = m::mock(Statement::class);
        $s1b = m::mock(Statement::class);
        $s2 = m::mock(Statement::class);

        $cache->set('k1', $s1a);
        $cache->set('k2', $s2);

        $cache->set('k1', $s1b);
        $cache->set('k1', $s1b);
        $cache->set('k1', $s1b);

        self::assertSame($s1b, $cache->get('k1'));
        self::assertSame($s2, $cache->get('k2'));

        $s3 = m::mock(Statement::class);
        $cache->set('k3', $s3);

        // After the last get('k2'), k1 becomes LRU and should be evicted
        self::assertNull($cache->get('k1'));
        self::assertSame($s2, $cache->get('k2'));
        self::assertSame($s3, $cache->get('k3'));
    }
}
