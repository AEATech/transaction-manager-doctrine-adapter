<?php
declare(strict_types=1);

namespace AEATech\TransactionManager\DoctrineAdapter\StatementExecutor;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use PDO;

/**
 * Best-effort binder helper:
 * - supports DBAL4 types: ParameterType|Type|string
 * - supports PDO::PARAM_* ints from core
 * - converts values using a DBAL Type + platform when applicable
 */
class BindingInfoResolver
{
    /**
     * @return array{0:mixed,1:ParameterType} [value, bindingType]
     *
     * @throws Exception
     */
    public function resolve(Connection $connection, mixed $value, mixed $type): array
    {
        if (is_array($value)) {
            throw new InvalidArgumentException('Array parameter values are not supported (no parameter expansion).');
        }

        if (null === $type) {
            return [$value, ParameterType::STRING];
        }

        // PDO::PARAM_* int -> DBAL ParameterType enum
        if (is_int($type)) {
            $bindingType = match ($type) {
                PDO::PARAM_INT  => ParameterType::INTEGER,
                PDO::PARAM_BOOL => ParameterType::BOOLEAN,
                PDO::PARAM_NULL => ParameterType::NULL,
                PDO::PARAM_LOB  => ParameterType::LARGE_OBJECT,
                default         => ParameterType::STRING,
            };

            return [$value, $bindingType];
        }

        // DBAL string type name -> Type instance
        if (is_string($type)) {
            $type = Type::getType($type);
        }

        // DBAL Type instance -> convert + binding type
        if ($type instanceof Type) {
            $platform = $connection->getDatabasePlatform();
            $value = $type->convertToDatabaseValue($value, $platform);

            return [$value, $type->getBindingType()];
        }

        // DBAL4 native enum
        if ($type instanceof ParameterType) {
            return [$value, $type];
        }

        throw new InvalidArgumentException(sprintf('Unsupported param type descriptor: %s', get_debug_type($type)));
    }
}
