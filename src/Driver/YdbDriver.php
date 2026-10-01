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
     * @param array{url?: string, driverOptions?: array{url?: string}, host?: string,
     *        port?: int, dbname?: string} $params
     *        Either this driver's own 'url'/'driverOptions.url' convention (a single DSN
     *        string - still what direct DriverManager/test usage passes), or DBAL's standard
     *        decomposed shape (host/port/dbname, plus whatever the DSN's query string
     *        contained, merged flat - see DsnParser::parseDatabaseUrlQuery()). The latter is
     *        what reaches here once Symfony's dbal.driver_schemes maps "ydb" to this class
     *        (see README): that's what makes DoctrineBundle parse the DSN itself instead of
     *        leaving it untouched for this driver to parse on its own.
     */
    public function connect(array $params): DriverConnection
    {
        $this->overrideBaseTypes();

        $dbUri = $params['url'] ?? $params['driverOptions']['url'] ?? null;

        return null !== $dbUri
            ? YdbConnection::makeConnectionByUrl($dbUri, $this->logger)
            : YdbConnection::makeConnectionByConfig(self::configFromDbalParams($params), $this->logger);
    }

    /**
     * Mirrors YdbUriParser::parse()'s output shape so both paths feed the SDK identically:
     * 'discovery' is forced false the same way, and query-string-style extras (iam_config,
     * etc.) get the same string->bool coercion DBAL's own DsnParser leaves undone (parse_str
     * only ever produces strings).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function configFromDbalParams(array $params): array
    {
        $endpoint = ($params['host'] ?? throw new \Exception())
            . (isset($params['port']) ? ':' . $params['port'] : '');

        // Anything DBAL itself didn't put here came from the DSN's own query string
        // (?discovery=...&iam_config[...]=...) - this driver's own config, not DBAL's.
        $extra = array_diff_key($params, array_flip(self::DBAL_RESERVED_PARAMS));
        array_walk_recursive($extra, static function (&$value): void {
            $value = match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            };
        });

        return [
            'database' => '/' . ltrim((string) ($params['dbname'] ?? ''), '/'),
            'endpoint' => $endpoint,
            'discovery' => false,
        ] + $extra;
    }

    /**
     * Every key DBAL's DriverManager itself ever puts in $params (its own Params phpstan
     * type), plus 'url' (DoctrineBundle's own DSN-convention key, already consumed above by
     * the time this runs for a bare DriverManager/test call that passes it directly) - not
     * from a DSN's own query string, so excluded from the pass-through "extra" config above.
     */
    private const DBAL_RESERVED_PARAMS = [
        'application_name', 'charset', 'connectstring', 'dbname', 'defaultTableOptions',
        'driver', 'driverClass', 'driverOptions', 'gssencmode', 'host', 'instancename',
        'keepReplica', 'memory', 'password', 'path', 'persistent', 'pooled', 'port',
        'primary', 'replica', 'serverVersion', 'service', 'servicename', 'sessionMode',
        'sid', 'ssl_ca', 'ssl_capath', 'ssl_cert', 'ssl_cipher', 'ssl_key', 'sslcert',
        'sslcrl', 'sslkey', 'sslmode', 'sslrootcert', 'unix_socket', 'user', 'wrapperClass',
        'url',
    ];

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
