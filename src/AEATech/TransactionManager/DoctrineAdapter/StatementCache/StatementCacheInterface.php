<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementCache;

use Doctrine\DBAL\Statement;

/**
 * Minimal cache for Doctrine DBAL prepared statements.
 *
 * Best-effort optimization only:
 * - Implementations MAY evict entries at any time (e.g., LRU).
 * - Callers MUST NOT rely on cached entries being present.
 */
interface StatementCacheInterface
{
    /**
     * Returns cached statement or null on cache miss.
     */
    public function get(string $key): ?Statement;

    /**
     * Stores the statement in a cache (may evict another entry).
     */
    public function set(string $key, Statement $stmt): void;

    /**
     * Clears the cache.
     */
    public function clear(): void;
}
