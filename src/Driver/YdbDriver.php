<?php

namespace Dudev\YdbDoctrine\Driver;

use Dudev\YdbDoctrine\Type\DateTimeType;
use Dudev\YdbDoctrine\Type\DateTimeTzType;
use Dudev\YdbDoctrine\Type\DecimalType;
use Dudev\YdbDoctrine\Type\FloatType;
use Dudev\YdbDoctrine\Type\JsonType;
use Dudev\YdbDoctrine\Type\WireTypeDecorator;
use Dudev\YdbDoctrine\YdbPlatform;
use Dudev\YdbDoctrine\YdbTypes;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

class YdbDriver implements Driver
{
    private ?LoggerInterface $logger = null;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param array{url?: string, driverOptions?: array{url?: string}} $params 'url' is
     *        this driver's own connection-string convention, not a standard DBAL param.
     */
    public function connect(array $params): DriverConnection
    {
        $dbUri = $params['url'] ?? $params['driverOptions']['url'] ?? throw new \Exception();
        $this->overrideBaseTypes();

        return YdbConnection::makeConnectionByUrl($dbUri, $this->logger);
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    private static function overrideBaseTypes(): void
    {
        Type::overrideType(Types::DATETIME_MUTABLE, DateTimeType::class);
        Type::overrideType(Types::DATETIMETZ_MUTABLE, DateTimeTzType::class);
        Type::overrideType(Types::FLOAT, FloatType::class);
        Type::overrideType(Types::JSON, JsonType::class);
        Type::overrideType(Types::DECIMAL, DecimalType::class);

        /*
         * Same wire-type problem as the five overrides above, minus a bespoke
         * subclass each - see WireTypeDecorator's docblock. These are the
         * "correctly declared column, silently mis-bound value" cases from
         * docs/YDB-TYPE-MAPPING.md findings #8/#9 - DATETIME_MUTABLE/
         * DATETIMETZ_MUTABLE were already covered above, but DATE_MUTABLE
         * turned out to have the exact same gap (confirmed live, not just in
         * the doc) despite never being flagged there as broken.
         */
        self::wireType(Types::DATE_MUTABLE, YdbTypes::DATE);
        self::wireType(Types::DATE_IMMUTABLE, YdbTypes::DATE);
        self::wireType(Types::DATETIME_IMMUTABLE, YdbTypes::DATETIME);
        self::wireType(Types::DATETIMETZ_IMMUTABLE, YdbTypes::DATETIME);
        self::wireType(Types::GUID, YdbTypes::UUID);

        /*
         * Same gap again, found by auditing every MAP_TO_DBAL_TYPES entry's
         * write path instead of assuming DDL-correct means bind-correct:
         * BigIntType::getBindingType() returns STRING (not the plain int
         * fallback), so Types::BIGINT fell into the ParameterType::STRING
         * branch and bound as Utf8; SmallIntType falls into
         * ParameterType::INTEGER, which YdbStatement's fallback hardcodes to
         * 'INT32', not 'INT16'. Types::INTEGER checked too and is fine as-is
         * - its INTEGER/'INT32' fallback already matches YdbTypes::INTEGER.
         */
        self::wireType(Types::BIGINT, YdbTypes::INT64);
        self::wireType(Types::SMALLINT, YdbTypes::INT16);

        /*
         * symfony/uid's own Doctrine type, registered under the name 'uuid'
         * (not Types::GUID='guid') - optional dependency, only wrap it if
         * something already registered it (normally DoctrineBridge's bundle
         * config does this before a Connection is ever opened).
         */
        if (Type::hasType('uuid')) {
            self::wireType('uuid', YdbTypes::UUID);
        }
    }

    /**
     * connect() runs this on every call, but Type's registry is a process-wide
     * static singleton that outlives any single connection (e.g. across
     * PHPUnit test methods, each opening its own Connection in the same
     * process) - re-wrapping an already-wired type here would nest a second
     * WireTypeDecorator around the first, making convertToDatabaseValue()
     * return a TypedValue whose ->value is itself a TypedValue instead of a
     * plain value. Skip types already wired.
     */
    private static function wireType(string $name, string $ydbType): void
    {
        if (Type::getType($name) instanceof WireTypeDecorator) {
            return;
        }

        Type::overrideType($name, new WireTypeDecorator(Type::getType($name), $name, $ydbType));
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return new YdbPlatform();
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new YdbExceptionConverter();
    }
}
