<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementExecutor;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Statement;

/**
 * Executes a prepared Doctrine DBAL statement with explicit parameter binding.
 *
 * Why this class exists:
 *
 * Doctrine DBAL 4 intentionally moved away from direct support of PDO-style
 * binding semantics (PDO::PARAM_* ints) in high-level APIs such as
 * Connection::executeStatement(). As a result:
 *
 * - Passing PDO::PARAM_* types directly to executeStatement() may trigger
 *   TypeErrors in DBAL 4.
 * - executeStatement() always performs an internal parameter traversal and
 *   type resolution, even when a prepared statement is reused.
 * - There is no public API to execute a prepared Statement while reusing
 *   already prepared SQL and still supplying custom parameter type mappings.
 *
 * This class solves these problems by:
 *
 * 1. Executing already prepared DBAL statements directly, enabling safe reuse
 *    of prepared statements (per-transaction or per-connection).
 * 2. Performing explicit parameter binding using the wrapped driver statement,
 *    avoiding DBAL's internal bindParameters() and its additional overhead.
 * 3. Supporting mixed parameter type descriptors:
 *    - DBAL ParameterType enum (DBAL 4 native)
 *    - DBAL mapping Type instances or type names
 *    - Legacy PDO::PARAM_* integer constants
 * 4. Ensuring predictable and fail-fast behavior when unsupported parameter
 *    values (e.g., arrays) or invalid type descriptors are provided.
 *
 * Design notes:
 *
 * - Parameter arrays may be positional or named; mixing is not allowed and
 *   follows Doctrine DBAL semantics.
 * - Positional parameters are rebound sequentially (1-indexed), independent
 *   of original array keys, allowing "gaps" in the parameter index space.
 * - Missing parameter types default to STRING, matching Doctrine DBAL behavior.
 * - This class does NOT support array parameter expansion; such values are
 *   rejected explicitly.
 *
 * This executor is a low-level infrastructure component used by
 * statement-caching connection adapters and MUST NOT introduce implicit
 * reconnects or retry logic.
 */
class StatementExecutor
{
    public function __construct(
        private readonly BindingInfoResolver $bindingInfoResolver
    ) {
    }

    /**
     * @param Connection $connection
     * @param Statement $stmt
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     *
     * @return int
     *
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function execute(Connection $connection, Statement $stmt, array $params, array $types): int
    {
        $isPositional = is_int(array_key_first($params));

        $wrappedStmt = $stmt->getWrappedStatement();

        if ($isPositional) {
            $bindIndex = 1; // 1-indexed for '?'

            foreach ($params as $key => $value) {
                if (isset($types[$key]) || array_key_exists($key, $types)) {
                    [$value, $bindingType] = $this->bindingInfoResolver->resolve($connection, $value, $types[$key]);
                } else {
                    $bindingType = ParameterType::STRING;
                }

                $wrappedStmt->bindValue($bindIndex, $value, $bindingType);

                $bindIndex++;
            }
        } else {
            foreach ($params as $name => $value) {
                if (isset($types[$name]) || array_key_exists($name, $types)) {
                    [$value, $bindingType] = $this->bindingInfoResolver->resolve($connection, $value, $types[$name]);
                } else {
                    $bindingType = ParameterType::STRING;
                }

                // $name must match a placeholder format used in SQL (':id' vs. 'id')
                $wrappedStmt->bindValue($name, $value, $bindingType);
            }
        }

        return (int) $wrappedStmt->execute()->rowCount();
    }
}
