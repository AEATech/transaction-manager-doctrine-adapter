<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementCache;

use Doctrine\DBAL\Statement;

/**
 * @internal
 * @codeCoverageIgnore
 */
class LruNode
{
    public ?self $prev = null;
    public ?self $next = null;

    public function __construct(
        public readonly string $key,
        public Statement $value,
    ) {
    }
}
