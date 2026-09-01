<?php

namespace Dudev\YdbDoctrine\ORM;

use Dudev\YdbDoctrine\ORM\Listener\ReferentialIntegrityListener;
use Dudev\YdbDoctrine\ORM\Query\YdbWalker;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\Events;
use Doctrine\ORM\QueryBuilder;

final class EntityManager extends EntityManagerDecorator
{
    public function __construct(Connection $conn, Configuration $config, EventManager $eventManager = null)
    {
        // Query::HINT_CUSTOM_OUTPUT_WALKER as a default query hint (rather than
        // setting it per-Query) survives AbstractQuery::__clone(), which discards
        // whatever hints were set on the instance and re-reads them from here.
        $config->setDefaultQueryHints($config->getDefaultQueryHints() + [
            Query::HINT_CUSTOM_OUTPUT_WALKER => YdbWalker::class,
        ]);

        $entityManager = new \Doctrine\ORM\EntityManager($conn, $config, $eventManager);

        // YDB has no FOREIGN KEY support (see YdbPlatform::getCreateTablesSQL()) -
        // wired in here, rather than left for each consumer to remember, so every
        // project using this EntityManager gets the check "for free". See
        // ReferentialIntegrityListener's own docblock for what it does and doesn't cover.
        $entityManager->getEventManager()->addEventListener(Events::onFlush, new ReferentialIntegrityListener());

        parent::__construct($entityManager);
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
