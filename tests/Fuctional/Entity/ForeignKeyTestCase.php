<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional\Entity;

use Dudev\YdbDoctrine\Tests\App\Entity\Post;
use Dudev\YdbDoctrine\Tests\App\Entity\User;
use Dudev\YdbDoctrine\Tests\Fuctional\AbstractFunctionalCase;

class ForeignKeyTestCase extends AbstractFunctionalCase
{
    /**
     * YDB has no FOREIGN KEY constraint support at all (see
     * YdbPlatform::getCreateTablesSQL()) - pins that a ManyToOne/JoinColumn
     * association still round-trips correctly at the ORM/data level (the FK
     * *column* and its value) even though no DB-level constraint is ever
     * created, and that schema create/drop across two related entities doesn't
     * fail trying to add or drop a constraint that was never made.
     */
    public function testManyToOneAssociationWorksWithoutADbLevelConstraint(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [
            User::class,
            Post::class,
        ]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $em->persist($user);

            $post = new Post(1, 'Hello world', $user);
            $em->persist($post);
            $em->flush();
            $em->clear();

            $post2 = $em->find(Post::class, 1);
            $this->assertNotEmpty($post2);
            $this->assertEquals('Hello world', $post2->title);
            $this->assertEquals($user->id, $post2->author->id);
        } finally {
            // Also pins that dropSchema() for related entities doesn't fail
            // trying to drop a constraint that was never created.
            $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $tool->dropSchema([$em->getClassMetadata(Post::class), $em->getClassMetadata(User::class)]);
        }
    }
}
