<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional\Entity;

use Dudev\YdbDoctrine\ORM\Exception\OrphanAssociationException;
use Dudev\YdbDoctrine\ORM\Exception\RestrictedDeletionException;
use Dudev\YdbDoctrine\Tests\App\Entity\Post;
use Dudev\YdbDoctrine\Tests\App\Entity\Profile;
use Dudev\YdbDoctrine\Tests\App\Entity\User;
use Dudev\YdbDoctrine\Tests\Fuctional\AbstractFunctionalCase;

class ReferentialIntegrityTestCase extends AbstractFunctionalCase
{
    /** @param list<class-string> $entityClasses */
    private function dropSchema(\Doctrine\ORM\EntityManagerInterface $em, array $entityClasses): void
    {
        $classes = [];
        foreach ($entityClasses as $className) {
            $classes[] = $em->getClassMetadata($className);
        }

        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $tool->dropSchema($classes);
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
            $this->dropSchema($em, [Post::class, User::class]);
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
            $this->dropSchema($em, [Post::class, User::class]);
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
            $this->dropSchema($em, [Post::class, User::class]);
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
            $this->dropSchema($em, [Post::class, User::class]);
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
            $this->dropSchema($em, [Post::class, User::class]);
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
            $this->dropSchema($em, [Post::class, User::class]);
        }
    }

    /**
     * The everyday case, oddly absent until now: every other test either
     * fails on purpose or persists the parent and child together in one
     * flush, which takes the isScheduledForInsert() shortcut rather than the
     * real existence check. This one commits a User in its own flush first,
     * then - in a *separate*, later flush - persists a Post referencing that
     * already-existing User by id via getReference(), forcing the actual
     * `exists($em, $targetClass, $identifier)` SELECT to run and succeed.
     */
    public function testReferencingAnAlreadyCommittedEntityInALaterFlushSucceeds(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $em->persist($user);
            $em->flush();
            $em->clear();

            $userRef = $em->getReference(User::class, 1);
            assert($userRef instanceof User);
            $post = new Post(1, 'Hello world', $userRef);
            $em->persist($post);
            $em->flush();
            $em->clear();

            $found = $em->find(Post::class, 1);
            $this->assertNotNull($found);
            $this->assertEquals(1, $found->author->id);
        } finally {
            $this->dropSchema($em, [Post::class, User::class]);
        }
    }

    /**
     * A null to-one association must be completely ignored by both the
     * existence check and the "target scheduled for delete" check - Post's
     * optional $reviewer is never set here, so persisting must not attempt to
     * look anything up for it at all.
     */
    public function testANullAssociationIsIgnored(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $post = new Post(1, 'Hello world', $user);
            $this->assertNull($post->reviewer);

            $em->persist($user);
            $em->persist($post);
            $em->flush();

            $found = $em->find(Post::class, 1);
            $this->assertNotNull($found);
            $this->assertNull($found->reviewer);
        } finally {
            $this->dropSchema($em, [Post::class, User::class]);
        }
    }

    /**
     * Mirrors testDeletingAFetchedEntityWhileReferencingItInTheSameFlushThrows,
     * but on the *update* path rather than insert: an already-persisted Post
     * is re-pointed at a User that is simultaneously scheduled for deletion in
     * the same flush. Both insert and update funnel through the same
     * checkAssociations() code, but only the insert side had a test.
     */
    public function testUpdatingAnAssociationToAnEntityScheduledForDeletionInTheSameFlushThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user1 = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $user2 = new User(2, 'Petr', 30, new \DateTimeImmutable(), true, false);
            $post = new Post(1, 'Hello world', $user1);
            $em->persist($user1);
            $em->persist($user2);
            $em->persist($post);
            $em->flush();

            $em->remove($user2);
            $post->author = $user2;

            $this->expectException(OrphanAssociationException::class);
            $em->flush();
        } finally {
            $this->dropSchema($em, [Post::class, User::class]);
        }
    }

    /**
     * If a User has two Posts and only one of them is deleted together with
     * the User in the same flush, the User must still be blocked from
     * deletion - the other Post is not going anywhere. A single-dependent
     * test can't distinguish "blocks unless every dependent is also being
     * deleted" (correct) from a broken "blocks unless at least one dependent
     * is also being deleted" (wrong) - this one can.
     */
    public function testDeletingAnEntityWithOneOfSeveralDependentsStillDeletedThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Post::class]);

        try {
            $user = new User(1, 'Ivan', 43, new \DateTimeImmutable(), true, false);
            $post1 = new Post(1, 'First', $user);
            $post2 = new Post(2, 'Second', $user);
            $em->persist($user);
            $em->persist($post1);
            $em->persist($post2);
            $em->flush();

            $em->remove($post1);
            $em->remove($user);
            // $post2 is deliberately left alone - it still references $user.

            $this->expectException(RestrictedDeletionException::class);
            $em->flush();
        } finally {
            $this->dropSchema($em, [Post::class, User::class]);
        }
    }

    /**
     * Proves ReferentialIntegrityListener's claimed OneToOne coverage - Profile
     * is a OneToOneOwningSideMapping fixture, distinct from Post's
     * ManyToOneAssociationMapping, and Doctrine always marks its single-column
     * join column unique (the entire semantic difference from ManyToOne). That
     * used to make schema creation fail outright, since UNIQUE indexes weren't
     * supported at all; now that YdbPlatform::getIndexDeclarationSQL() emits
     * YDB's real (undocumented) "GLOBAL UNIQUE SYNC" syntax, schema creation
     * succeeds and this can finally test what it was always meant to: that the
     * listener's ToOneOwningSideMapping check catches a dangling OneToOne
     * reference the same way it does for ManyToOne.
     */
    public function testOneToOneAssociationToAMissingRowThrows(): void
    {
        $em = $this->createEntityManager();
        $this->generateSchema($em, [User::class, Profile::class]);

        try {
            $ghostUserRef = $em->getReference(User::class, 999);
            assert($ghostUserRef instanceof User);
            $profile = new Profile(1, 'Bio', $ghostUserRef);

            $em->persist($profile);

            $this->expectException(OrphanAssociationException::class);
            $this->expectExceptionMessageMatches('/references a .*User.*that does not exist/');
            $em->flush();
        } finally {
            $this->dropSchema($em, [Profile::class, User::class]);
        }
    }
}
