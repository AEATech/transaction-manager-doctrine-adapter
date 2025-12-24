# AEATech Transaction Manager — Doctrine Adapter

![Code Coverage](.build/coverage_badge.svg)

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
  - Caching (with statement reuse support):
    - `AEATech\TransactionManager\DoctrineAdapter\DbalMysqlStatementCachingConnectionAdapter`
    - `AEATech\TransactionManager\DoctrineAdapter\DbalPostgresStatementCachingConnectionAdapter`
  - Simple (no caching):
    - `AEATech\TransactionManager\DoctrineAdapter\DbalMysqlConnectionAdapter`
    - `AEATech\TransactionManager\DoctrineAdapter\DbalPostgresConnectionAdapter`

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

- `None`: No caching — each call prepares a fresh statement (may result in prepare-per-call behavior for server-side prepared statements).
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
- Keys are suitable for best-effort reuse only and MUST NOT be relied upon for semantic correctness.

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

`DbalMysqlStatementCachingConnectionAdapter::beginTransactionWithOptions()` sets the isolation level for the next transaction (if provided in `$options`) and then begins the transaction:

```php
$adapter->beginTransactionWithOptions($options); 
// executes: SET TRANSACTION ISOLATION LEVEL ... (only if isolationLevel is set), then BEGIN
```

### PostgreSQL

`DbalPostgresStatementCachingConnectionAdapter::beginTransactionWithOptions()` begins a transaction first and then sets isolation for the current transaction only (if provided in `$options`):

```php
$adapter->beginTransactionWithOptions($options); 
// executes: BEGIN; then SET TRANSACTION ISOLATION LEVEL ... (only if isolationLevel is set)
```

Both adapters:
- Support optional `isolationLevel`. If `null`, no isolation level command is issued, and the database/session default is used.
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

## Choosing the Right Adapter

Selecting the appropriate adapter depends on your database, prepared statement mode, and performance characteristics of your workload.

### Adapter Selection Matrix

| Database | Mode | Recommended Adapter | Why?                                                                                                                                   |
|----------|------|---------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| **MySQL** | Server-side Prepares | `DbalMysqlStatementCachingConnectionAdapter` | **Significant performance gain.** avoids repeated COM_STMT_PREPARE round-trips and server-side parsing.                                |
| **MySQL** | Emulated Prepares | `DbalMysqlConnectionAdapter` | **Simpler and sufficient.** PDO client-side emulation is already efficient; statement caching provides no measurable benefit.          |
| **PostgreSQL** | Native | `DbalPostgresConnectionAdapter` | **Recommended default.** `pdo_pgsql` preparation overhead is low; client-side caching yields only marginal gains in typical workloads. |
| **PostgreSQL** | Complex/Heavy load | `DbalPostgresStatementCachingConnectionAdapter` | **Optional optimization.** May reduce allocation and preparation overhead under extreme load or highly repetitive statement execution. |

### Recommendations

1. **Use Caching Adapters for MySQL Server-Side Prepares:** If you have `PDO::ATTR_EMULATE_PREPARES => false`, prefer `DbalMysqlStatementCachingConnectionAdapter`. Reusing prepared statements avoids repeated server-side prepares and can result in 2× or greater throughput improvements for statement-heavy workloads.
2. **MySQL with Emulated Prepared Statements:** When using client-side emulation, DbalMysqlConnectionAdapter is the preferred choice. It is lighter, simpler to configure, and provides identical performance without maintaining an internal statement cache.
3. **PostgreSQL Default Usage:** Start with DbalPostgresConnectionAdapter. PostgreSQL already handles prepared statements efficiently via the extended query protocol ,and statement caching typically provides only 1–3% improvements in microbenchmarks.
4. **Memory-Constrained Environments:** When memory usage is critical, prefer non-caching adapters. They avoid maintaining internal LRU caches and provide more predictable memory behavior.

For detailed performance measurements and the rationale behind these recommendations, see the [Benchmarking](#benchmarking) section.

## Benchmarking

The package includes a benchmark suite to measure the effectiveness of prepared statement reuse across different databases and configurations.

### Running Benchmarks

A dedicated script `bench.sh` is provided to run benchmarks in a controlled environment with CPU pinning to reduce noise.

```bash
# Run all benchmarks (MySQL and PostgreSQL)
./bench.sh all

# Run only MySQL benchmarks
./bench.sh mysql

# Run only PostgreSQL benchmarks
./bench.sh pgsql
```

The script performs the following actions:
1. Starts Docker containers for PHP, MySQL, and PostgreSQL.
2. Waits for databases to become healthy.
3. Pins the PHP process and database processes to specific CPU cores to ensure stable measurements.
4. Executes `phpbench` within the PHP container.

### Results and Analysis

Typical results (measured on PHP 8.4, Opcache enabled, Xdebug disabled):

| Database | Mode | Subject | No Cache | With Cache | Improvement |
|----------|------|---------|----------|------------|-------------|
| **MySQL** | Server-side Prepares | Simple Query | ~52μs | ~22μs | **~57%** |
| **MySQL** | Server-side Prepares | Complex Query | ~59μs | ~24μs | **~59%** |
| **MySQL** | Emulated Prepares | Simple Query | ~31μs | ~31μs | ~0% |
| **MySQL** | Emulated Prepares | Complex Query | ~38μs | ~38μs | ~0% |
| **PostgreSQL** | Native | Simple Query | ~38μs | ~37μs | ~2% |
| **PostgreSQL** | Native | Complex Query | ~41μs | ~40μs | ~2% |

> **Note:**  
> In the `No Cache` scenario with MySQL server-side prepared statements, the benchmark intentionally performs a full `PREPARE` on every execution.  
> This represents a worst-case usage pattern where statements are not reused at all.  
> The observed speedup therefore reflects the cost of repeated server-side prepares rather than the overhead of the cache itself.

#### Key Conclusions:

1. **MySQL Server-Side Prepared Statements**: Reusing prepared statements provides the most significant performance improvement (often more than 2× faster). This is because it avoids repeated server-side PREPARE operations and query parsing.
2. **MySQL Emulated Prepared Statements**: When prepared statements are emulated on the client side, caching provides no measurable benefit. In this mode, PDO already avoids additional round-trips for statement preparation.
3. **PostgreSQL**: PostgreSQL handles prepared statements efficiently via the extended query protocol. In the tested scenarios, most of the execution time is dominated by network round-trips and parameter binding, resulting in only marginal gains (~1–3%) from client-side statement caching.

#### Benchmark scope:
These benchmarks are designed to measure adapter-level behavior in a controlled environment.
Absolute numbers should not be treated as universal performance characteristics of MySQL or PostgreSQL.

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

### Run phpstan
```bash
docker-compose -p aeatech-transaction-manager-doctrine-adapter -f docker/docker-compose.yml exec php-cli-8.4 vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G
```

## License

This project is licensed under the MIT License. See the [LICENSE](./LICENSE) file for details.