<?php

namespace Dudev\YdbDoctrine\Driver;

use Dudev\YdbDoctrine\Type\DateTimeType;
use Dudev\YdbDoctrine\Type\DateTimeTzType;
use Dudev\YdbDoctrine\Type\DecimalType;
use Dudev\YdbDoctrine\Type\FloatType;
use Dudev\YdbDoctrine\Type\JsonType;
use Dudev\YdbDoctrine\YdbPlatform;
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
