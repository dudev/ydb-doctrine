<?php

namespace Dudev\YdbDoctrine\ORM\Exception;

class RestrictedDeletionException extends \RuntimeException
{
    public static function create(string $entityClass, string $dependentClass, string $dependentField): self
    {
        return new self(sprintf(
            "Cannot delete a %s: a %s still references it through '\$%s'. YDB has no FOREIGN KEY " .
            'support, so there is no ON DELETE CASCADE/SET NULL either - remove or repoint the ' .
            'dependent row(s) first (or delete them in the same flush). See ' .
            'Dudev\YdbDoctrine\ORM\Listener\ReferentialIntegrityListener.',
            $entityClass,
            $dependentClass,
            $dependentField,
        ));
    }
}
