<?php

namespace Dudev\YdbDoctrine\Tests\App\Doctrine;

use Doctrine\Persistence\AbstractManagerRegistry;

class ManagerRegistry extends AbstractManagerRegistry
{
    protected function getService(string $name): object
    {
        throw new \Exception('ManagerRegistry::getService not implemented');
    }

    protected function resetService(string $name): void
    {
    }
}
