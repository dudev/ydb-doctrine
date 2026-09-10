<?php

namespace Dudev\YdbDoctrine\Type;

use Dudev\YdbDoctrine\Value\TypedValue;
use Dudev\YdbDoctrine\YdbTypes;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class SmallFloatType extends \Doctrine\DBAL\Types\SmallFloatType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (null === $value) {
            return $value;
        }

        return new TypedValue((float) $value, YdbTypes::FLOAT);
    }
}
