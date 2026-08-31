<?php

namespace Dudev\YdbDoctrine\Tests\App\Repository;

use Dudev\YdbDoctrine\Tests\App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends EntityRepository<User> */
class UserRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        $entityClass = User::class;
        $manager = $registry->getManagerForClass($entityClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException("No entity manager registered for $entityClass");
        }

        parent::__construct($manager, $manager->getClassMetadata($entityClass));
    }
}
