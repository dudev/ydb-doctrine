<?php

namespace Dudev\YdbDoctrine\Type;

use Dudev\YdbDoctrine\Value\TypedValue;
use Dudev\YdbDoctrine\YdbTypes;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

class DateTimeTzType extends Type
{
    public function getName(): string
    {
        return Types::DATETIMETZ_MUTABLE;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDateTimeTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return new TypedValue($value, YdbTypes::DATETIME);
        }

        throw InvalidType::new($value, $this->getName(), ['null', 'DateTime']);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value || $value instanceof \DateTimeInterface) {
            return $value;
        }

        $val = \DateTime::createFromFormat($platform->getDateTimeFormatString(), $value);

        if (false === $val) {
            $val = date_create($value);
        }

        if (false === $val) {
            throw InvalidFormat::new(
                $value,
                $this->getName(),
                $platform->getDateTimeFormatString(),
            );
        }

        return $val;
    }
}
