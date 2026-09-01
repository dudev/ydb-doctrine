<?php

namespace Dudev\YdbDoctrine\ORM\Exception;

class OrphanAssociationException extends \RuntimeException
{
    /** @param array<string, mixed> $identifier */
    public static function create(string $sourceClass, string $fieldName, string $targetClass, array $identifier): self
    {
        $idString = implode(', ', array_map(
            static fn (string $key, mixed $value): string => $key . '=' . var_export($value, true),
            array_keys($identifier),
            array_values($identifier),
        ));

        return new self(sprintf(
            "%s::\$%s references a %s that does not exist (%s). YDB has no FOREIGN KEY support, " .
            'so ydb-doctrine checks referenced-row existence at flush time instead - see ' .
            'Dudev\YdbDoctrine\ORM\Listener\ReferentialIntegrityListener.',
            $sourceClass,
            $fieldName,
            $targetClass,
            $idString,
        ));
    }
}
