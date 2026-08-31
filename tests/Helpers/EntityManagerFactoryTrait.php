<?php

namespace Dudev\YdbDoctrine\Tests\Helpers;

use Dudev\YdbDoctrine\Driver\YdbDriver;
use Dudev\YdbDoctrine\ORM\EntityManager;
use Dudev\YdbDoctrine\ORM\Functions\Rand;
use Dudev\YdbDoctrine\YdbPlatform;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;

trait EntityManagerFactoryTrait
{
    public function makeEntityManager(): EntityManager
    {
        $this->createMock(YdbDriver::class);
        $connect = $this->createMock(Connection::class);
        $connect->method('getDatabasePlatform')->willReturn(new YdbPlatform());

        $configuration = new Configuration();
        $configuration->addCustomStringFunction('RAND', Rand::class);
        $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/App/Entity']));
        $configuration->setProxyDir(__DIR__ . '/App');
        $configuration->setProxyNamespace('App');

        return new EntityManager($connect, $configuration);
    }
}
