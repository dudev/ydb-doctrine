<?php

namespace Dudev\YdbDoctrine\Type;

use Dudev\YdbDoctrine\Value\TypedValue;
use Dudev\YdbDoctrine\YdbTypes;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class DecimalType extends \Doctrine\DBAL\Types\DecimalType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return $value;
        }

        return new TypedValue((string) $value, YdbTypes::DECIMAL);
    }
}
