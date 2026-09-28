<?php

namespace Dudev\YdbDoctrine\ORM;

use Dudev\YdbDoctrine\ORM\Listener\ReferentialIntegrityListener;
use Dudev\YdbDoctrine\ORM\Query\YdbWalker;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\QueryBuilder;

/**
 * Constructor takes the already-built EntityManagerInterface it wraps, not raw
 * Connection/Configuration - so this class can be wired as a Symfony service
 * decorator (`decorates: doctrine.orm.default_entity_manager`, `$wrapped: '@.inner'`,
 * see README) instead of needing DoctrineBundle to construct it directly, which it
 * has no supported way to do (there is no "entity manager class" extension point -
 * `doctrine.orm.entity_manager.abstract`'s class is hardcoded to
 * Doctrine\ORM\EntityManager in the bundle's own service definitions). Use
 * self::create() instead when you do have raw Connection/Configuration and want
 * this wrapping without going through Symfony's container (e.g. tests).
 */
final class EntityManager extends EntityManagerDecorator
{
    public function __construct(EntityManagerInterface $wrapped)
    {
        self::configure($wrapped);

        parent::__construct($wrapped);
    }

    public static function create(Connection $conn, Configuration $config, EventManager $eventManager = null): self
    {
        return new self(new \Doctrine\ORM\EntityManager($conn, $config, $eventManager));
    }

    private static function configure(EntityManagerInterface $entityManager): void
    {
        $config = $entityManager->getConfiguration();

        // Query::HINT_CUSTOM_OUTPUT_WALKER as a default query hint (rather than
        // setting it per-Query) survives AbstractQuery::__clone(), which discards
        // whatever hints were set on the instance and re-reads them from here.
        $config->setDefaultQueryHints($config->getDefaultQueryHints() + [
            Query::HINT_CUSTOM_OUTPUT_WALKER => YdbWalker::class,
        ]);

        // YDB has no FOREIGN KEY support - wired in here so every consumer gets
        // the check for free (see ReferentialIntegrityListener for what it covers).
        $entityManager->getEventManager()->addEventListener(Events::onFlush, new ReferentialIntegrityListener());
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /** @return Query<mixed, mixed> */
    public function createQuery($dql = ''): Query
    {
        $query = new Query($this);
        if (!empty($dql)) {
            $query->setDQL($dql);
        }

        return $query;
    }
}
