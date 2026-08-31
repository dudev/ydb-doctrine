<?php

namespace Dudev\YdbDoctrine\Type;

use Dudev\YdbDoctrine\Value\TypedValue;
use Dudev\YdbDoctrine\YdbTypes;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * Doesn't extend \Doctrine\DBAL\Types\JsonType: its convertToDatabaseValue()
 * is narrowed to `?string`, which is incompatible with returning a TypedValue.
 */
class JsonType extends Type
{
    public function getName(): string
    {
        return Types::JSON;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return $value;
        }

        return new TypedValue(json_encode($value, JSON_THROW_ON_ERROR), YdbTypes::JSON);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (!$value) {
            return null;
        }

        return (array) $value;
    }
}
