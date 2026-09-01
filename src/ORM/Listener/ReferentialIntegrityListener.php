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
 * YDB has no FOREIGN KEY support (see YdbPlatform::getCreateTablesSQL()); this
 * is the library-level replacement. On insert/update, every non-null to-one
 * owning-side association is checked for a live referenced row, inside the
 * same transaction flush() opens. A target scheduled for insertion in the
 * same flush is skipped (commit ordering already guarantees it exists by
 * then); a target scheduled for deletion in the same flush throws
 * immediately without querying, since the row is still physically present
 * when onFlush runs and a plain existence check would miss it.
 *
 * The delete side is the ON DELETE RESTRICT equivalent: deleting a row another
 * already-persisted row still references is rejected, unless that dependent
 * is itself being deleted in the same flush.
 *
 * Known limitation: a dependent scheduled for *update* (repointing its
 * association away, rather than being deleted) still blocks - conservatively
 * treated as still referencing the parent, since it does until that update
 * executes. Work around it by flushing the repoint first, then deleting the
 * parent in a second flush.
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

    /** @param list<string>|null $onlyFields fields changed this flush, or null for every association */
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
        // A mapped class whose table doesn't exist yet (e.g. an unmigrated new
        // entity) has no dependents by definition - guard instead of crashing.
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
