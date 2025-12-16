<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementCache;

use AEATech\TransactionManager\Query;

/**
 * Builds a cache key for a prepared statement.
 *
 * NOTE:
 * This is used for best-effort statement reuse only.
 * It MUST NOT be relied upon for correctness.
 */
interface StatementCacheKeyBuilderInterface
{
    public function build(Query $query): string;
}
