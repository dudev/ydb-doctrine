<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional\Entity;

use Dudev\YdbDoctrine\Tests\App\Entity\AutoincrementEntity;
use Dudev\YdbDoctrine\Tests\Fuctional\AbstractFunctionalCase;

/** Regression coverage for EntityManager::persist()/flush() on an IDENTITY-generated entity, which needs Driver\YdbConnection::lastInsertId() to actually return a value. */
class AutoincrementEntityTestCase extends AbstractFunctionalCase
{
    public function testPersistAssignsSequentialGeneratedIds(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [AutoincrementEntity::class]);

        try {
            $first = new AutoincrementEntity('first');
            $em->persist($first);
            $em->flush();

            $second = new AutoincrementEntity('second');
            $em->persist($second);
            $em->flush();

            $this->assertSame(1, $first->id);
            $this->assertSame(2, $second->id);
        } finally {
            $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $tool->dropSchema([$em->getClassMetadata(AutoincrementEntity::class)]);
        }
    }
}
