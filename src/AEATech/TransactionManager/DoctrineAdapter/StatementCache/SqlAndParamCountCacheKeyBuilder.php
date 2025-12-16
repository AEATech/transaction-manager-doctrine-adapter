<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementCache;

use AEATech\TransactionManager\Query;

class SqlAndParamCountCacheKeyBuilder implements StatementCacheKeyBuilderInterface
{
    public function build(Query $query): string
    {
        $shape = 'p:' . count($query->params);

        $h = hash('sha256', $query->sql);

        return $h . '|' . $shape;
    }
}
