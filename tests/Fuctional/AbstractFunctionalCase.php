<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Dudev\YdbDoctrine\Driver\YdbDriver;
use Dudev\YdbDoctrine\ORM\EntityManager;
use Dudev\YdbDoctrine\Tests\App\Entity\User;
use Dudev\YdbDoctrine\YdbConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;

abstract class AbstractFunctionalCase extends TestCase
{
    protected YdbConnection $connection;

    /**
     * YDB has no SAVEPOINT support (confirmed absent from the YQL syntax reference,
     * and every official SDK models a transaction as a single flat state machine
     * with no nesting concept) - opening a transaction here unconditionally would
     * make every test that begins its own (directly, via transactional(), or via
     * EntityManager::flush()) hit Connection::beginTransaction()'s nested path,
     * which always fails for this driver (YdbPlatform::supportsSavepoints() is
     * false). Tests that want rollback-based isolation open their own transaction
     * and roll it back themselves; tests that don't, don't - there is no implicit
     * transaction wrapping this base class provides.
     */
    public function setUp(): void
    {
        $this->connection = new YdbConnection(['url' => $_ENV['YDB_URL']], new YdbDriver());
    }

    public function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->close();
    }

    protected function createEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: array(__DIR__ . "/../App"),
            isDevMode: true,
        );

        return new EntityManager($this->connection, $config);
    }

    /** @param list<class-string> $entityClasses */
    public function generateSchema(EntityManagerInterface $em, array $entityClasses): void
    {
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $classes = [];
        foreach ($entityClasses as $className) {
            $classes = [$em->getClassMetadata($className)];
        }

        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
