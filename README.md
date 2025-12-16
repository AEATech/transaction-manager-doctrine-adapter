# AEATech Transaction Manager — Doctrine Adapter

Doctrine DBAL adapter for AEATech Transaction Manager with best‑effort prepared‑statement reuse and explicit parameter binding compatible with both DBAL 3 and DBAL 4.

## Key Features

- Statement reuse policies: `None`, `PerTransaction`, `PerConnection`.
- Lightweight O(1) LRU cache for prepared statements.
- Stable cache key builder based on SQL hash and parameter count.
- Low‑level statement executor with explicit parameter binding.
- Supports Doctrine DBAL types and legacy `PDO::PARAM_*` integer constants.
- Correct handling of transaction boundaries for MySQL and PostgreSQL.

## Contents (API Surface)

- Connection adapters
  - `AEATech\TransactionManager\DoctrineAdapter\DbalMysqlStatementCachingConnectionAdapter`
  - `AEATech\TransactionManager\DoctrineAdapter\DbalPostgresStatementCachingConnectionAdapter`
  - Base class: `AEATech\TransactionManager\DoctrineAdapter\AbstractStatementCachingConnectionAdapter`

- Statement execution
  - `AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor`
  - `AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\BindingInfoResolver`

- Statement caching
  - `AEATech\TransactionManager\DoctrineAdapter\StatementCache\StatementCacheInterface`
  - `AEATech\TransactionManager\DoctrineAdapter\StatementCache\LruStatementCache`
  - `AEATech\TransactionManager\DoctrineAdapter\StatementCache\StatementCacheKeyBuilderInterface`
  - `AEATech\TransactionManager\DoctrineAdapter\StatementCache\SqlAndParamCountCacheKeyBuilder`

## Installation

```bash
composer require aeatech/transaction-manager-doctrine-adapter
```

## Quick Start

```php
use AEATech\TransactionManager\DoctrineAdapter\DbalMysqlStatementCachingConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\LruStatementCache;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\SqlAndParamCountCacheKeyBuilder;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\BindingInfoResolver;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\StatementReusePolicy;
use Doctrine\DBAL\DriverManager;

$conn = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => '127.0.0.1',
    'dbname' => 'app',
    'user' => 'app',
    'password' => 'secret',
]);

$executor   = new StatementExecutor(new BindingInfoResolver());
$perTxCache = new LruStatementCache(100);
$perConnCache = new LruStatementCache(500);
$keyBuilder = new SqlAndParamCountCacheKeyBuilder();

$adapter = new DbalMysqlStatementCachingConnectionAdapter(
    $conn,
    $executor,
    $keyBuilder,
    $perTxCache,
    $perConnCache,
);

// Execute parametrized query with statement reuse
$q = new Query(
    'UPDATE accounts SET balance = balance - ? WHERE id = ?',
    [100, 42]
);
$q->statementReusePolicy = StatementReusePolicy::PerTransaction;

$affected = $adapter->executeQuery($q);
```

## Statement Reuse Policies

- `None`: No caching — each call prepares a fresh statement.
- `PerTransaction`: Prepared statements are cached for the current transaction and dropped on `commit()`/`rollBack()`.
- `PerConnection`: Prepared statements are cached for the lifetime of the connection and dropped on `close()`.

## Statement Cache

### LRU Cache

`LruStatementCache` provides O(1) get/set and evicts the least‑recently used entry when capacity is exceeded.

Implementation notes:
- The cache stores `Doctrine\DBAL\Statement` objects.
- Eviction happens only when `set()` pushes the size above capacity.
- `clear()` drops the entire cache (used on transaction/connection boundaries).

### Cache Key Builder

`SqlAndParamCountCacheKeyBuilder` builds keys as `sha256(sql) | 'p:' . count(params)`.

Implications:
- Only SQL text and parameter count are considered. Parameter values, names, and order for named parameters are intentionally ignored.
- Keys are suitable for best‑effort reuse only and MUST NOT be relied upon for correctness guarantees.

## StatementExecutor (explicit binding)

`StatementExecutor` executes an already prepared DBAL `Statement` and performs explicit parameter binding using the wrapped driver statement. This bypasses DBAL’s internal binding logic and supports a broader set of type descriptors for compatibility across DBAL versions.

Supported type descriptors for each parameter:
- `Doctrine\DBAL\ParameterType` (DBAL 4 enum)
- `Doctrine\DBAL\Types\Type` instance
- DBAL type name as string (e.g., `'integer'`, `'string'`)
- Legacy `PDO::PARAM_*` ints (`PDO::PARAM_INT`, `PDO::PARAM_BOOL`, `PDO::PARAM_NULL`, `PDO::PARAM_LOB`)

Binding rules:
- Positional parameters are bound sequentially as 1‑based positions (`?`). Original array keys may have gaps — binding order follows array iteration order.
- Named parameters must match the placeholders used in SQL exactly (e.g., `':id'` vs `'id'`). The executor does not normalize names.
- Missing types default to `ParameterType::STRING`.
- Array parameter values are not supported (no automatic `IN (...)` expansion) and are rejected.

Compatibility notes:
- DBAL string type names are resolved via `Type::getType($name)`, then `convertToDatabaseValue()` is applied using the active platform, and `getBindingType()` is used for the driver bind.
- `PDO::PARAM_*` integers are mapped to the corresponding `ParameterType` where applicable.

## Transaction Adapters

### MySQL/MariaDB

`DbalMysqlStatementCachingConnectionAdapter::beginTransactionWithOptions()` sets the isolation level for the next transaction and then begins the transaction:

```php
$adapter->beginTransactionWithOptions($options); // executes: SET TRANSACTION ISOLATION LEVEL ..., then BEGIN
```

### PostgreSQL

`DbalPostgresStatementCachingConnectionAdapter::beginTransactionWithOptions()` begins a transaction first and then sets isolation for the current transaction only:

```php
$adapter->beginTransactionWithOptions($options); // executes: BEGIN; SET TRANSACTION ISOLATION LEVEL ...
```

Both adapters:
- Throw if a transaction is already active.
- Clear the per‑transaction cache on every transaction boundary (`begin` with options, `commit`, `rollBack`).

## Edge Cases and Limitations

- Statement caching is the best effort; entries may be evicted at any time due to capacity limits.
- Named parameter keys must match the SQL placeholders format exactly; no normalization is performed.
- No array parameter expansion is provided; arrays as values are rejected.
- `rowCount()` is returned from the driver result and may be 0 for unsupported operations depending on the driver.
- Reusing prepared statements across transaction boundaries is disallowed for `PerTransaction` and prevented by clearing caches.

## Examples

### Positional parameters with mixed types

```php
use Doctrine\DBAL\ParameterType;

$q = new Query('UPDATE t SET a = ? WHERE id = ?', ['10', 5]);
$q->types = [0 => 'integer', 1 => ParameterType::INTEGER];
$q->statementReusePolicy = StatementReusePolicy::PerTransaction;

$adapter->executeQuery($q);
```

### Named parameters with explicit DBAL types

```php
use Doctrine\DBAL\ParameterType;

$q = new Query('UPDATE t SET a = :a WHERE id = :id', [':a' => 'x', ':id' => 10]);
$q->types = [':a' => 'string', ':id' => ParameterType::INTEGER];
$q->statementReusePolicy = StatementReusePolicy::PerConnection;

$adapter->executeQuery($q);
```

### Using PDO type constants

```php
use PDO;

$q = new Query('INSERT INTO files(data) VALUES(?)', [$blob]);
$q->types = [PDO::PARAM_LOB];

$adapter->executeQuery($q);
```

## Testing

Make sure the Docker containers are up and running. From the project root:

```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml up -d --build
```

### Install Dependencies

```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.2 composer install
```

### Run Tests

PHP 8.2
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.2 vendor/bin/phpunit
```

PHP 8.3
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.3 vendor/bin/phpunit
```

PHP 8.4
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.4 vendor/bin/phpunit
```

### Run All Tests (Bash Script)

```bash
for v in 8.2 8.3 8.4; do \
    echo "Testing PHP $v..."; \
    docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec -T php-cli-$v vendor/bin/phpunit || break; \
done
```

## License

This project is licensed under the MIT License. See the [LICENSE](./LICENSE) file for details.
