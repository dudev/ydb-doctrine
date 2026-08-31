<?php

namespace Dudev\YdbDoctrine\Value;

/**
 * Carries the intended YDB wire type (a Dudev\YdbDoctrine\YdbTypes::* constant)
 * alongside a bound parameter value.
 *
 * Doctrine\DBAL\ParameterType (DBAL >= 4) is a closed native enum limited to
 * NULL/INTEGER/STRING/LARGE_OBJECT/BOOLEAN/BINARY/ASCII, so it can no longer carry
 * YDB-specific hints (Datetime/Json/Float/Decimal/...) down to the driver. Custom
 * Doctrine\DBAL\Types\Type::convertToDatabaseValue() implementations wrap the
 * converted value in this class instead; YdbStatement unwraps it when building
 * the YQL DECLARE for the bound parameter.
 */
final class TypedValue
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $ydbType,
    ) {
    }
}
