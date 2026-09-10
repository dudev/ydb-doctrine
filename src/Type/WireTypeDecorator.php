<?php

namespace Dudev\YdbDoctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Dudev\YdbDoctrine\Value\TypedValue;

/**
 * Wraps an existing Doctrine Type so its convertToDatabaseValue() result carries
 * the YQL wire type YdbStatement needs to DECLARE it as.
 *
 * Doctrine's Connection/Statement resolve $type->getBindingType() and
 * $type->convertToDatabaseValue() *before* calling the driver's
 * Statement::bindValue() - by the time YdbStatement sees the value, the
 * originating Type (its name, its class) is gone, only a generic ParameterType
 * (STRING/INTEGER/...) and the already-converted value remain. TypedValue is
 * the only channel left to carry "this needs to be a YQL Datetime/Uuid/..." across
 * that boundary, so any Type without its own custom convertToDatabaseValue()
 * silently binds as whatever its plain converted value looks like (usually a
 * generic Utf8 string) - wrong for every wire type but String/Bool/Int32.
 *
 * One registration per affected type (see YdbDriver::overrideBaseTypes()) instead
 * of a bespoke subclass each - covers third-party types (e.g. symfony/uid's
 * UuidType) the same way as core DBAL ones.
 */
final class WireTypeDecorator extends Type
{
    public function __construct(
        private readonly Type $inner,
        private readonly string $name,
        private readonly string $ydbType,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $this->inner->getSQLDeclaration($column, $platform);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        $value = $this->inner->convertToDatabaseValue($value, $platform);

        return null === $value ? null : new TypedValue($value, $this->ydbType);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return $this->inner->convertToPHPValue($value, $platform);
    }

    /** Never actually read by YdbStatement (it branches on instanceof YdbBoundValue before looking at this), kept only to satisfy Type's contract. */
    public function getBindingType(): ParameterType
    {
        return $this->inner->getBindingType();
    }
}
