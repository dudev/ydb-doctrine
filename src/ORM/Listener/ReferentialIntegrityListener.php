<?php

namespace Dudev\YdbDoctrine\ORM\Listener;

use Dudev\YdbDoctrine\ORM\Exception\OrphanAssociationException;
use Dudev\YdbDoctrine\ORM\Exception\RestrictedDeletionException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\ORM\UnitOfWork;

/**
 * YDB has no FOREIGN KEY support (see YdbPlatform::getCreateTablesSQL()), so
 * nothing at the database level stops an insert/update from writing a to-one
 * association that points at a row which doesn't exist. This listener is the
 * library-level replacement: on every flush, for every to-one owning-side
 * association (ManyToOne, owning-side OneToOne) that is currently non-null on
 * an entity being inserted or updated, it verifies the referenced row actually
 * exists - inside the same transaction flush() already opens, so YDB's
 * serializable isolation covers the race between this check and the commit
 * (a concurrent delete of the referenced row aborts the transaction instead of
 * silently racing past it).
 *
 * An association pointing at an entity that is itself scheduled for insertion
 * in this same flush is skipped: Doctrine's own commit ordering already
 * guarantees the parent row is inserted before the child, regardless of DB
 * engine, so there is nothing to check yet.
 *
 * An association pointing at an entity that is scheduled for *deletion* in
 * this same flush is rejected outright, without even querying the database:
 * confirmed live that without this, `$em->remove($user); $post->author =
 * $user; $em->persist($post); $em->flush();` succeeds silently and leaves
 * post.author_id pointing at a row that no longer exists the moment this
 * transaction commits - the plain existence check alone can't catch it
 * because onFlush fires before any SQL runs, so the row is still physically
 * there when the SELECT would execute.
 *
 * The second half of this listener covers the opposite direction: deleting a
 * row that other, already-persisted rows still reference (the ON DELETE
 * RESTRICT equivalent). For every entity scheduled for deletion, it scans
 * every *other* mapped entity class for a to-one owning-side association
 * targeting this one, and checks whether any row in that dependent table
 * currently points at the row being deleted. A dependent row that is itself
 * scheduled for deletion in this same flush doesn't count - deleting a parent
 * together with its dependents in one flush is meant to work, same spirit as
 * the insert-side skip above.
 *
 * Known limitation, not handled: a dependent row that is scheduled for
 * *update* in this same flush to repoint its association away from the row
 * being deleted (rather than being deleted itself). That row still physically
 * references the parent at the moment this check runs (onFlush fires before
 * any SQL executes), so it is conservatively treated as still blocking - the
 * same restriction an FK constraint without deferred checking would impose.
 * Work around it by flushing the repoint first, then deleting the parent in a
 * second flush.
 */
class ReferentialIntegrityListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->checkAssociations($em, $uow, $entity, null);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->checkAssociations($em, $uow, $entity, array_keys($uow->getEntityChangeSet($entity)));
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->checkDependents($em, $uow, $entity);
        }
    }

    /**
     * @param list<string>|null $onlyFields null means "check every to-one association";
     *                                      otherwise restrict to fields that actually
     *                                      changed in this flush (an update that never
     *                                      touched the association was already checked
     *                                      when it was first set).
     */
    private function checkAssociations(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        object $entity,
        ?array $onlyFields,
    ): void {
        $class = $em->getClassMetadata($entity::class);

        foreach ($class->getAssociationMappings() as $mapping) {
            if (!$mapping instanceof ToOneOwningSideMapping) {
                continue;
            }

            if (null !== $onlyFields && !in_array($mapping->fieldName, $onlyFields, true)) {
                continue;
            }

            $related = $class->getFieldValue($entity, $mapping->fieldName);
            if (null === $related) {
                continue;
            }

            if ($uow->isScheduledForInsert($related)) {
                continue;
            }

            $targetClass = $em->getClassMetadata($mapping->targetEntity);
            $identifier = $targetClass->getIdentifierValues($related);
            if ([] === $identifier || in_array(null, $identifier, true)) {
                continue;
            }

            if ($uow->isScheduledForDelete($related)) {
                throw OrphanAssociationException::create(
                    $entity::class,
                    $mapping->fieldName,
                    $mapping->targetEntity,
                    $identifier,
                );
            }

            if ($this->exists($em, $targetClass, $identifier)) {
                continue;
            }

            throw OrphanAssociationException::create(
                $entity::class,
                $mapping->fieldName,
                $mapping->targetEntity,
                $identifier,
            );
        }
    }

    /**
     * @param ClassMetadata<object> $targetClass
     * @param array<string, mixed>  $identifier
     */
    private function exists(EntityManagerInterface $em, ClassMetadata $targetClass, array $identifier): bool
    {
        $conditions = [];
        $params = [];
        $types = [];
        foreach ($identifier as $fieldName => $value) {
            $conditions[] = $targetClass->getColumnName($fieldName) . ' = ?';
            $params[] = $value;
            $types[] = $targetClass->getTypeOfField($fieldName) ?? Types::STRING;
        }

        $sql = 'SELECT 1 FROM ' . $targetClass->getTableName() . ' WHERE ' . implode(' AND ', $conditions);

        return null !== $em->getConnection()->fetchOne($sql, $params, $types);
    }

    private function checkDependents(EntityManagerInterface $em, UnitOfWork $uow, object $entity): void
    {
        foreach ($em->getMetadataFactory()->getAllMetadata() as $dependentClass) {
            foreach ($dependentClass->getAssociationMappings() as $mapping) {
                if (!$mapping instanceof ToOneOwningSideMapping) {
                    continue;
                }

                if (!$entity instanceof $mapping->targetEntity) {
                    continue;
                }

                if ($this->hasBlockingDependent($em, $uow, $dependentClass, $mapping, $entity)) {
                    throw RestrictedDeletionException::create(
                        $entity::class,
                        $dependentClass->getName(),
                        $mapping->fieldName,
                    );
                }
            }
        }
    }

    /** @param ClassMetadata<object> $dependentClass */
    private function hasBlockingDependent(
        EntityManagerInterface $em,
        UnitOfWork $uow,
        ClassMetadata $dependentClass,
        ToOneOwningSideMapping $mapping,
        object $sourceEntity,
    ): bool {
        // checkDependents() scans every *mapped* class, regardless of whether its
        // table has actually been created yet (e.g. a migration for a newly added
        // entity hasn't run in this environment) - confirmed live that without this
        // guard, that case crashes with a raw scheme error instead of correctly
        // treating "the table doesn't exist" as "nothing in it references this row".
        if (!$em->getConnection()->createSchemaManager()->tablesExist([$dependentClass->getTableName()])) {
            return false;
        }

        $sourceClass = $em->getClassMetadata($mapping->targetEntity);

        /** @var array<string, array{0: mixed, 1: string}> $sourceIdentifierByColumn */
        $sourceIdentifierByColumn = [];
        foreach ($sourceClass->getIdentifierValues($sourceEntity) as $fieldName => $value) {
            $sourceIdentifierByColumn[$sourceClass->getColumnName($fieldName)] = [
                $value,
                $sourceClass->getTypeOfField($fieldName) ?? Types::STRING,
            ];
        }

        $conditions = [];
        $params = [];
        $types = [];
        foreach ($mapping->joinColumns as $joinColumn) {
            if (!isset($sourceIdentifierByColumn[$joinColumn->referencedColumnName])) {
                return false;
            }

            [$value, $type] = $sourceIdentifierByColumn[$joinColumn->referencedColumnName];
            $conditions[] = $joinColumn->name . ' = ?';
            $params[] = $value;
            $types[] = $type;
        }

        if ([] === $conditions) {
            return false;
        }

        $idColumns = $dependentClass->getIdentifierColumnNames();
        $sql = 'SELECT ' . implode(', ', $idColumns) . ' FROM ' . $dependentClass->getTableName() .
            ' WHERE ' . implode(' AND ', $conditions);

        foreach ($em->getConnection()->fetchAllAssociative($sql, $params, $types) as $row) {
            if (!$this->rowIsScheduledForDeletion($dependentClass, $row, $uow)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassMetadata<object> $dependentClass
     * @param array<string, mixed>  $row             keyed by column name, as returned by fetchAllAssociative()
     */
    private function rowIsScheduledForDeletion(ClassMetadata $dependentClass, array $row, UnitOfWork $uow): bool
    {
        $rowIdentifierByField = [];
        foreach ($row as $columnName => $value) {
            $rowIdentifierByField[$dependentClass->getFieldName((string) $columnName)] = (string) $value;
        }

        $dependentClassName = $dependentClass->getName();
        foreach ($uow->getScheduledEntityDeletions() as $scheduled) {
            if (!$scheduled instanceof $dependentClassName) {
                continue;
            }

            $scheduledIdentifier = [];
            foreach ($dependentClass->getIdentifierValues($scheduled) as $fieldName => $value) {
                $scheduledIdentifier[$fieldName] = (string) $value;
            }

            if ($scheduledIdentifier === $rowIdentifierByField) {
                return true;
            }
        }

        return false;
    }
}
