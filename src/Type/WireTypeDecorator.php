<?php

namespace Dudev\YdbDoctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Dudev\YdbDoctrine\Value\TypedValue;

/** Wraps a Type so convertToDatabaseValue() tags its result with the YQL wire type YdbStatement needs to bind it correctly. */
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
