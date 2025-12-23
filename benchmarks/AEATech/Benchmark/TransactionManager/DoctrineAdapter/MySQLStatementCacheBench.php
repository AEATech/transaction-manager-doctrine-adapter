<?php
declare(strict_types=1);

namespace AEATech\Benchmark\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\DoctrineAdapter\DbalMysqlStatementCachingConnectionAdapter;
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
 * - Emulated vs server-side prepared statements (PDO::ATTR_EMULATE_PREPARES)
 * - No statement reuse vs per-connection statement reuse
 * - Simple vs more complex SQL statements
 */
#[BeforeClassMethods(['setUpClass'])]
#[BeforeMethods(['setUp'])]
#[Warmup(1)]
#[Revs(1000)]
#[Iterations(50)]
class MySQLStatementCacheBench extends AbstractStatementCacheBench
{
    private DbalMysqlStatementCachingConnectionAdapter $adapterWithEmulatePrepareStmt;
    private DbalMysqlStatementCachingConnectionAdapter $adapterWithServerSidePrepareStmt;

    /**
     * @throws Throwable
     */
    public static function setUpClass(): void
    {
        self::initPresets(self::makeConnection(true));
    }

    private static function makeConnection(bool $emulatePrepares): Connection
    {
        $params = [
            'driver'        => 'pdo_mysql',
            'host'          => getenv('MYSQL_HOST'),
            'port'          => 3306,
            'dbname'        => 'test',
            'user'          => 'root',
            'password'      => '',
            'charset'       => 'utf8mb4',
            'driverOptions' => [
                PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ];

        return DriverManager::getConnection($params, new Configuration());
    }

    public function setUp(): void
    {
        $connectionWithEmulatePrepareStmt = self::makeConnection(true);
        $connectionWithServerSidePrepareStmt = self::makeConnection(false);

        $executor = new StatementExecutor(new BindingInfoResolver());
        $keyBuilder = new SqlAndParamCountCacheKeyBuilder();

        $perTxCacheWithEmulatePrepareStmt = new LruStatementCache(100);
        $perConnCacheWithEmulatePrepareStmt = new LruStatementCache(100);
        $this->adapterWithEmulatePrepareStmt = new DbalMysqlStatementCachingConnectionAdapter(
            $connectionWithEmulatePrepareStmt,
            $executor,
            $keyBuilder,
            $perTxCacheWithEmulatePrepareStmt,
            $perConnCacheWithEmulatePrepareStmt
        );

        $perTxCacheWithServerSidePrepareStmt = new LruStatementCache(100);
        $perConnCacheWithServerSidePrepareStmt = new LruStatementCache(100);
        $this->adapterWithServerSidePrepareStmt = new DbalMysqlStatementCachingConnectionAdapter(
            $connectionWithServerSidePrepareStmt,
            $executor,
            $keyBuilder,
            $perTxCacheWithServerSidePrepareStmt,
            $perConnCacheWithServerSidePrepareStmt
        );
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['simple-query', 'emulate-prepare-stmt', 'mysql'])]
    public function simpleNoCacheEmulatePrepareStmt(): void
    {
        $this->runSimpleQuery($this->adapterWithEmulatePrepareStmt, StatementReusePolicy::None);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['simple-query', 'emulate-prepare-stmt', 'mysql'])]
    public function simpleWithCacheEmulatePrepareStmt(): void
    {
        $this->runSimpleQuery($this->adapterWithEmulatePrepareStmt, StatementReusePolicy::PerConnection);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['simple-query', 'server-side-prepare-stmt', 'mysql'])]
    public function simpleNoCacheServerSidePrepareStmt(): void
    {
        $this->runSimpleQuery($this->adapterWithServerSidePrepareStmt, StatementReusePolicy::None);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['simple-query', 'server-side-prepare-stmt', 'mysql'])]
    public function simpleWithCacheServerSidePrepareStmt(): void
    {
        $this->runSimpleQuery($this->adapterWithServerSidePrepareStmt, StatementReusePolicy::PerConnection);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['complex', 'emulate-prepare-stmt', 'mysql'])]
    public function complexNoCacheEmulatePrepareStmt(): void
    {
        $this->runComplexQuery($this->adapterWithEmulatePrepareStmt, StatementReusePolicy::None);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['complex', 'emulate-prepare-stmt', 'mysql'])]
    public function complexWithCacheEmulatePrepareStmt(): void
    {
        $this->runComplexQuery($this->adapterWithEmulatePrepareStmt, StatementReusePolicy::PerConnection);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['complex','server-side-prepare-stmt'])]
    public function complexNoCacheServerSidePrepareStmt(): void
    {
        $this->runComplexQuery($this->adapterWithServerSidePrepareStmt, StatementReusePolicy::None);
    }

    /**
     * @throws Throwable
     */
    #[Subject]
    #[Groups(['complex', 'server-side-prepare-stmt', 'mysql'])]
    public function complexWithCacheServerSidePrepareStmt(): void
    {
        $this->runComplexQuery($this->adapterWithServerSidePrepareStmt, StatementReusePolicy::PerConnection);
    }
}