<?php
declare(strict_types=1);

namespace AEATech\Benchmark\TransactionManager\DoctrineAdapter;

use AEATech\TransactionManager\ConnectionInterface;
use AEATech\TransactionManager\Query;
use AEATech\TransactionManager\StatementReusePolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

abstract class AbstractStatementCacheBench
{
    protected const PRESET_SIZE = 50_000;

    protected int $nextId = 1;

    /**
     * @throws Throwable
     */
    protected static function initPresets(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS bench');
        $connection->executeStatement(
            'CREATE TABLE bench (
                id INT PRIMARY KEY,
                val TEXT NOT NULL,
                version INTEGER NOT NULL,
                status VARCHAR(16) NOT NULL,
                some_flag INTEGER NOT NULL,
                another_flag INTEGER NOT NULL
            )'
        );

        $connection->beginTransaction();

        try {
            $stmt = $connection->prepare(
                'INSERT INTO bench (id, val, version, status, some_flag, another_flag) VALUES (?, ?, ?, ?, ?, ?)'
            );

            $id = 1;

            for ($i = 0; $i < self::PRESET_SIZE; $i++) {
                $stmt->bindValue(1, $id);
                $stmt->bindValue(2, 'val');
                $stmt->bindValue(3, 1);
                $stmt->bindValue(4, 'active');
                $stmt->bindValue(5, 1);
                $stmt->bindValue(6, 0);
                $stmt->executeStatement();

                $id++;
            }

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    protected function takeId(): int
    {
        $id = $this->nextId++;

        if ($id > self::PRESET_SIZE) {
            $this->nextId = 1;
            $id = 1;
        }

        return $id;
    }

    /**
     * @throws Throwable
     */
    protected function runSimpleQuery(ConnectionInterface $adapter, StatementReusePolicy $policy): void
    {
        $id = $this->takeId();

        $q = new Query(
            'UPDATE bench SET version = version WHERE id = ? AND 1 = 0',
            [$id],
            [
                ParameterType::INTEGER,
            ],
            $policy
        );

        $adapter->executeQuery($q);
    }

    /**
     * @throws Throwable
     */
    protected function runComplexQuery(ConnectionInterface $adapter, StatementReusePolicy $policy): void
    {
        $id = $this->takeId();

        $q = new Query(
            'UPDATE bench
             SET val = ?, version = version + 1
             WHERE id = ? AND status = ? AND (some_flag = ? OR another_flag = ?) AND 1 = 0',
            [
                'updated',
                $id,
                'active',
                1,
                0,
            ],
            [
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ],
            $policy
        );

        $adapter->executeQuery($q);
    }
}
