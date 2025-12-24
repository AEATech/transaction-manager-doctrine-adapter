<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementCache;

use Doctrine\DBAL\Statement;
use InvalidArgumentException;

/**
 * Simple O(1) LRU cache for Doctrine DBAL Statements.
 *
 * Capacity is the maximum number of cached statements.
 * When capacity is exceeded, the least-recently used entry is evicted.
 *
 * This cache is intended for best-effort performance optimizations only.
 */
class LruStatementCache implements StatementCacheInterface
{
    private int $capacity;

    /**
     * @var array<string, LruNode>
     */
    private array $map = [];
    private ?LruNode $head = null; // most recently used
    private ?LruNode $tail = null; // least recently used

    public function __construct(int $capacity)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('LRU cache capacity must be >= 1.');
        }

        $this->capacity = $capacity;
    }

    public function get(string $key): ?Statement
    {
        $node = $this->map[$key] ?? null;

        if ($node === null) {
            return null;
        }

        $this->moveToFront($node);

        return $node->value;
    }

    public function set(string $key, Statement $stmt): void
    {
        $node = $this->map[$key] ?? null;

        if ($node !== null) {
            $node->value = $stmt;
            $this->moveToFront($node);
            return;
        }

        $node = new LruNode($key, $stmt);
        $this->map[$key] = $node;
        $this->addToFront($node);

        if (count($this->map) > $this->capacity) {
            $this->evictLeastRecentlyUsed();
        }
    }

    public function clear(): void
    {
        $this->map = [];
        $this->head = null;
        $this->tail = null;
    }

    private function evictLeastRecentlyUsed(): void
    {
        /**
         * @var LruNode $node
         */
        $node = $this->tail;

        // It is assumed that this method is called only when
        // the number of elements exceeds capacity; therefore, tail is not null.
        $this->removeNode($node);

        unset($this->map[$node->key]);
    }

    private function moveToFront(LruNode $node): void
    {
        $this->removeNode($node);
        $this->addToFront($node);
    }

    private function addToFront(LruNode $node): void
    {
        $oldHead = $this->head;

        $node->prev = null;
        $node->next = $oldHead;

        if ($oldHead !== null) {
            $oldHead->prev = $node;
        }

        $this->head = $node;

        if ($this->tail === null) {
            $this->tail = $node;
        }
    }

    private function removeNode(LruNode $node): void
    {
        $prev = $node->prev;
        $next = $node->next;

        if ($prev !== null) {
            $prev->next = $next;
        } else {
            $this->head = $next;
        }

        if ($next !== null) {
            $next->prev = $prev;
        } else {
            $this->tail = $prev;
        }

        $node->prev = null;
        $node->next = null;
    }
}
