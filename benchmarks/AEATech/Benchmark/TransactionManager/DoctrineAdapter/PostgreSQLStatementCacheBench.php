<?php
declare(strict_types=1);

namespace AEATech\Benchmark\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\DbalPostgresStatementCachingConnectionAdapter;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\LruStatementCache;
use AEATech\TransactionManager\DoctrineAdapter\StatementCache\SqlAndParamCountCacheKeyBuilder;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\BindingInfoResolver;
use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\StatementExecutor;
use AEATech\TransactionManager\StatementReusePolicy;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PDO;
use PhpBench\Attributes\BeforeClassMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Throwable;

/**
 * Benchmark for measuring prepared statement reuse effectiveness in Transaction Manager.
 *
 * IMPORTANT EXECUTION MODEL NOTES (phpbench remote executor):
 *
 * - Each benchmark iteration is executed in a **separate PHP process**.
 * - For each process:
 *   - A **new benchmark object instance** is created.
 *   - `BeforeMethods` (setUp) is executed **exactly once**.
 *   - Optional warmup runs are executed.
 *   - Measured `Revs` executions are performed.
 * - After that, the process terminates and **no state is preserved**.
 *
 * As a consequence:
 * - No state (including static properties) is shared between iterations.
 * - Any caching performed in this benchmark is scoped strictly to a
 *   **single process lifetime**, i.e., to the warmup + revs of one subject execution.
 * - This benchmark intentionally measures the steady-state behavior of
 *   prepared statement reuse **within one connection / one worker**,
 *   not cross-iteration or cross-process caching.
 *
 * Benchmark dimensions are covered:
 * - No statement reuse vs per-connection statement reuse
 * - Simple vs more complex SQL statements
 */
#[BeforeClassMethods(['setUpClass'])]
#[BeforeMethods(['setUp'])]
#[Warmup(1)]
#[Revs(1000)]
#[Iterations(50)]
class PostgreSQLStatementCacheBench extends AbstractStatementCacheBench
{
    private DbalPostgresStatementCachingConnectionAdapter $statementCachingConnectionAdapter;

    /**
     * @throws Throwable
     */
    public static function setUpClass(): void
    {
        self::initPresets(self::makeConnection());
    }

    private static function makeConnection(): Connection
    {
        $params = [
            'driver'        => 'pdo_pgsql',
            'host'          => getenv('PGSQL_HOST'),
            'port'          => 5432,
            'dbname'        => 'test',
            'user'          => 'test',
            'password'      => 'test',
            'charset'       => 'utf8',
            'driverOptions' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ];

        return DriverManager::getConnection($params, new Configuration());
    }

    public function setUp(): void
    {
        $connection = self::makeConnection();

        $executor = new StatementExecutor(new BindingInfoResolver());
        $keyBuilder = new SqlAndParamCountCacheKeyBuilder();

        $perTxCacheWithEmulatePrepareStmt = new LruStatementCache(10);
        $perConnCacheWithEmulatePrepareStmt = new LruStatementCache(10);
        $this->statementCachingConnectionAdapter = new DbalPostgresStatementCachingConnectionAdapter(
            $connection,
            $executor,
            $keyBuilder,
            $perTxCacheWithEmulatePrepareStmt,
            $perConnCacheWithEmulatePrepareStmt
        );
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['simple-query', 'emulate-prepare-stmt', 'pgsql'])]
    public function simpleNoCache(): void
    {
        $this->runSimpleQuery($this->statementCachingConnectionAdapter, StatementReusePolicy::None);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['simple-query', 'emulate-prepare-stmt', 'pgsql'])]
    public function simpleWithCache(): void
    {
        $this->runSimpleQuery($this->statementCachingConnectionAdapter, StatementReusePolicy::PerConnection);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['complex', 'emulate-prepare-stmt', 'pgsql'])]
    public function complexNoCache(): void
    {
        $this->runComplexQuery($this->statementCachingConnectionAdapter, StatementReusePolicy::None);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['complex', 'emulate-prepare-stmt', 'pgsql'])]
    public function complexWithCache(): void
    {
        $this->runComplexQuery($this->statementCachingConnectionAdapter, StatementReusePolicy::PerConnection);
    }
}
