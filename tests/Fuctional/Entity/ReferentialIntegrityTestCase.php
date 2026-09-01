<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional\Entity;

use Dudev\YdbDoctrine\ORM\Exception\OrphanAssociationException;
use Dudev\YdbDoctrine\ORM\Exception\RestrictedDeletionException;
use Dudev\YdbDoctrine\Tests\App\Entity\Post;
use Dudev\YdbDoctrine\Tests\App\Entity\User;
use Dudev\YdbDoctrine\Tests\Fuctional\AbstractFunctionalCase;

class ReferentialIntegrityTestCase extends AbstractFunctionalCase
{
    private function dropSchema(\Doctrine\ORM\EntityManagerInterface $em): void
    {
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema([$em->getClassMetadata(Post::class), $em->getClassMetadata(User::class)]);
    }

    /**
     * The user and the post it references are both new in the same flush -
     * ReferentialIntegrityListener must not reject this just because the user
     * doesn't exist in the database *yet*: Doctrine's own commit ordering
     * inserts the user first, so it exists by the time the post's INSERT runs.
     */
    public function testPersistingAssociatedEntitiesTogetherInTheSameFlushSucceeds(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $post = new Post(1, 'Hello world', $user);

            $em->persist($user);
            $em->persist($post);
            $em->flush();

            $this->assertNotEmpty($em->find(Post::class, 1));
        } finally {
            $this->dropSchema($em);
        }
    }

    /**
     * Pins the core guarantee. Uses EntityManager::getReference() to build the
     * dangling reference rather than `new User(...)`: a plain new, unpersisted
     * object is already rejected by Doctrine's own UnitOfWork ("A new entity
     * was found through the relationship...") before our listener even runs -
     * that's a different, pre-existing guard against unknown PHP objects, not
     * a check that the referenced *row* exists. getReference() is the
     * idiomatic, common way to set an association "by id" without loading it,
     * and Doctrine happily treats the resulting proxy as managed without ever
     * touching the database - exactly the gap this listener closes: YDB has
     * no FOREIGN KEY support, so nothing else stops this from silently writing
     * a dangling author_id.
     */
    public function testInsertingAnAssociationToAMissingRowThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $ghostUserRef = $em->getReference(User::class, 999);
            assert($ghostUserRef instanceof User);
            $post = new Post(1, 'Orphan', $ghostUserRef);

            $em->persist($post);

            $this->expectException(OrphanAssociationException::class);
            $this->expectExceptionMessageMatches('/references a .*User.*that does not exist/');
            $em->flush();
        } finally {
            $this->dropSchema($em);
        }
    }

    /**
     * Same guarantee on the update path: re-pointing an already-persisted
     * Post's author at a User id that doesn't exist must fail too, not just
     * the initial insert.
     */
    public function testUpdatingAnAssociationToAMissingRowThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $post = new Post(1, 'Hello world', $user);
            $em->persist($user);
            $em->persist($post);
            $em->flush();

            $ghostUserRef = $em->getReference(User::class, 999);
            assert($ghostUserRef instanceof User);
            $post->author = $ghostUserRef;

            $this->expectException(OrphanAssociationException::class);
            $em->flush();
        } finally {
            $this->dropSchema($em);
        }
    }

    /**
     * A row fetched from the database (not a getReference() proxy) still gets
     * checked - the existence check isn't skipped just because Doctrine
     * already loaded the full row once. This also pins the same-flush delete
     * race: removing a User (via a find()-loaded reference this time) while a
     * new Post is set to reference that same User in the same flush must fail
     * too, even though the row is still physically present in YDB at the
     * moment the check runs (onFlush fires before any SQL executes) -
     * confirmed live that without an explicit isScheduledForDelete() check
     * this silently commits a post.author_id pointing at a row deleted in the
     * very same transaction.
     */
    public function testDeletingAFetchedEntityWhileReferencingItInTheSameFlushThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $em->persist($user);
            $em->flush();
            $em->clear();

            $fetchedUser = $em->find(User::class, 1);
            $this->assertNotNull($fetchedUser);

            $em->remove($fetchedUser);
            $post = new Post(1, 'Race', $fetchedUser);
            $em->persist($post);

            $this->expectException(OrphanAssociationException::class);
            $em->flush();
        } finally {
            $this->dropSchema($em);
        }
    }

    /**
     * The delete-side guarantee (ON DELETE RESTRICT equivalent): a User that a
     * Post still references cannot be deleted on its own - YDB has no ON
     * DELETE RESTRICT/CASCADE to fall back on, so the library has to refuse it
     * explicitly instead of leaving post.author_id pointing at nothing.
     */
    public function testDeletingAReferencedEntityThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $post = new Post(1, 'Hello world', $user);
            $em->persist($user);
            $em->persist($post);
            $em->flush();

            $em->remove($user);

            $this->expectException(RestrictedDeletionException::class);
            $this->expectExceptionMessageMatches('/Cannot delete a .*User.*Post.*references it/');
            $em->flush();
        } finally {
            $this->dropSchema($em);
        }
    }

    /**
     * Deleting a parent together with its only dependent, in the same flush,
     * must succeed - the dependent won't actually be an orphan once this
     * flush commits, so it shouldn't count as "blocking" the parent's deletion.
     */
    public function testDeletingAnEntityTogetherWithItsDependentInTheSameFlushSucceeds(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $post = new Post(1, 'Hello world', $user);
            $em->persist($user);
            $em->persist($post);
            $em->flush();

            $em->remove($post);
            $em->remove($user);
            $em->flush();

            $this->assertNull($em->find(User::class, 1));
            $this->assertNull($em->find(Post::class, 1));
        } finally {
            $this->dropSchema($em);
        }
    }
}
