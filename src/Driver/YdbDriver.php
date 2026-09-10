<?php

namespace Dudev\YdbDoctrine\Driver;

use Dudev\YdbDoctrine\Type\DateTimeType;
use Dudev\YdbDoctrine\Type\DateTimeTzType;
use Dudev\YdbDoctrine\Type\DecimalType;
use Dudev\YdbDoctrine\Type\FloatType;
use Dudev\YdbDoctrine\Type\JsonType;
use Dudev\YdbDoctrine\Type\SmallFloatType;
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
        Type::overrideType(Types::SMALLFLOAT, SmallFloatType::class);
        Type::overrideType(Types::JSON, JsonType::class);
        Type::overrideType(Types::DECIMAL, DecimalType::class);

        // Same wire-type gap as the five overrides above.
        self::wireType(Types::DATE_MUTABLE, YdbTypes::DATE);
        self::wireType(Types::DATE_IMMUTABLE, YdbTypes::DATE);
        self::wireType(Types::DATETIME_IMMUTABLE, YdbTypes::DATETIME);
        self::wireType(Types::DATETIMETZ_IMMUTABLE, YdbTypes::DATETIME);
        self::wireType(Types::GUID, YdbTypes::UUID);
        self::wireType(Types::BIGINT, YdbTypes::INT64);
        self::wireType(Types::SMALLINT, YdbTypes::INT16);

        // symfony/uid's own type, registered as 'uuid' (not Types::GUID) - only wrap it if already registered.
        if (Type::hasType('uuid')) {
            self::wireType('uuid', YdbTypes::UUID);
        }
    }

    /** Type's registry outlives any single connect() call - skip types already wired to avoid double-wrapping. */
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
