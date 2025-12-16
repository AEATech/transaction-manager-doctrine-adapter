<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager\DoctrineAdapter\StatementExecutor;

use AEATech\TransactionManager\DoctrineAdapter\StatementExecutor\BindingInfoResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(BindingInfoResolver::class)]
class BindingInfoResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Connection&m\MockInterface $connection;
    private BindingInfoResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = m::mock(Connection::class);
        $this->resolver = new BindingInfoResolver();
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function nullTypeDefaultsToString(): void
    {
        [$val, $type] = $this->resolver->resolve($this->connection, 123, null);

        self::assertSame(123, $val);
        self::assertSame(ParameterType::STRING, $type);
    }

    /**
     * @throws Exception
     */
    #[Test]
    #[DataProvider('pdoParamIntsAreMappedDataProvider')]
    public function pdoParamIntsAreMapped(mixed $value, mixed $type, $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($this->connection, $value, $type)[1]);
    }

    public static function pdoParamIntsAreMappedDataProvider(): array
    {
        return [
            [
                'value' => '10',
                'type' => PDO::PARAM_INT,
                'expected' => ParameterType::INTEGER,
            ],
            [
                'value' => true,
                'type' => PDO::PARAM_BOOL,
                'expected' => ParameterType::BOOLEAN,
            ],
            [
                'value' => null,
                'type' => PDO::PARAM_NULL,
                'expected' => ParameterType::NULL,
            ],
            [
                'value' => 'bin',
                'type' => PDO::PARAM_LOB,
                'expected' => ParameterType::LARGE_OBJECT,
            ],
            // Unknown int -> STRING
            [
                'value' => 'x',
                'type' => 9999,
                'expected' => ParameterType::STRING,
            ]
        ];
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function stringDbalTypeNameIsResolved(): void
    {
        $platform = m::mock(AbstractPlatform::class);
        $this->connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        // Built-in type 'integer'
        [$val, $type] = $this->resolver->resolve($this->connection, '42', 'integer');
        // convertToDatabaseValue() of IntegerType leaves as-is; binding type is INTEGER

        self::assertSame('42', $val);
        self::assertSame(ParameterType::INTEGER, $type);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function typeInstanceIsUsedForConversionAndBinding(): void
    {
        $custom = new class extends Type {
            public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
            {
                return 'CUSTOM';
            }
            public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
            {
                return 'db:' . $value;
            }
            // Do NOT override getBindingType() to keep compatibility with both DBAL 3.x and 4.x.
            // Base Type::getBindingType() returns STRING in both versions (int in 3.x, enum in 4.x).

            public function getName(): string
            {
                return 'CUSTOM';
            }
        };

        $platform = m::mock(AbstractPlatform::class);
        $this->connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        [$val, $type] = $this->resolver->resolve($this->connection, 'x', $custom);

        self::assertSame('db:x', $val);
        self::assertSame(ParameterType::STRING, $type);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function parameterTypeEnumPassesThrough(): void
    {
        [$val, $type] = $this->resolver->resolve($this->connection, 'x', ParameterType::INTEGER);

        self::assertSame('x', $val);
        self::assertSame(ParameterType::INTEGER, $type);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function arrayValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve($this->connection, ['x'], null);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function unsupportedTypeDescriptorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve($this->connection, 'x', new stdClass());
    }
}
