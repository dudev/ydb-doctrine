<?php

namespace Dudev\YdbDoctrine\Driver;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

/**
 * YDB errors aren't SQLSTATE/vendor-error-code based, so there is no reliable
 * signal yet to map to the specialized DriverException subclasses (e.g.
 * TableNotFoundException, UniqueConstraintViolationException). Every exception
 * is reported as a generic DriverException until that mapping is implemented.
 */
final class YdbExceptionConverter implements ExceptionConverter
{
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        return new DriverException($exception, $query);
    }
}
