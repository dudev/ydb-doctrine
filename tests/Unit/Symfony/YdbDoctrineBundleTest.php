<?php

namespace Dudev\YdbDoctrine\Tests\Unit\Symfony;

use Dudev\YdbDoctrine\Driver\YdbDriver;
use Dudev\YdbDoctrine\ORM\EntityManager;
use Dudev\YdbDoctrine\YdbDoctrineBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class YdbDoctrineBundleTest extends TestCase
{
    public function testPrependsTheYdbSchemeToDoctrineBundle(): void
    {
        $container = $this->containerWithDoctrineExtension();

        $this->extension()->prepend($container);

        $this->assertSame(
            [['dbal' => ['driver_schemes' => ['ydb' => YdbDriver::class]]]],
            $container->getExtensionConfig('doctrine'),
        );
    }

    public function testPrependIsSkippedWithoutDoctrineBundle(): void
    {
        $container = $this->container();

        $this->extension()->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testDecoratesTheDefaultEntityManager(): void
    {
        $container = $this->container();
        $container->register('doctrine.orm.default_entity_manager', \stdClass::class)->setPublic(true);

        $this->extension()->load([[]], $container);
        $container->compile();

        $this->assertSame(EntityManager::class, $container->getDefinition('doctrine.orm.default_entity_manager')->getClass());
    }

    public function testDecoratesTheConfiguredEntityManager(): void
    {
        $container = $this->container();
        $container->register('doctrine.orm.ydb_entity_manager', \stdClass::class)->setPublic(true);
        $container->register('doctrine.orm.default_entity_manager', \stdClass::class)->setPublic(true);

        $this->extension()->load([['entity_manager' => 'ydb']], $container);
        $container->compile();

        $this->assertSame(EntityManager::class, $container->getDefinition('doctrine.orm.ydb_entity_manager')->getClass());
        $this->assertSame(\stdClass::class, $container->getDefinition('doctrine.orm.default_entity_manager')->getClass());
    }

    public function testNullEntityManagerDisablesDecoration(): void
    {
        $container = $this->container();

        $this->extension()->load([['entity_manager' => null]], $container);

        $this->assertFalse($container->hasDefinition(EntityManager::class));
    }

    public function testMissingEntityManagerIsIgnored(): void
    {
        $container = $this->container();

        $this->extension()->load([[]], $container);
        $container->getDefinition(EntityManager::class)->setPublic(true);
        $container->compile();

        $this->assertFalse($container->hasDefinition(EntityManager::class));
    }

    private function container(): ContainerBuilder
    {
        return new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
    }

    private function containerWithDoctrineExtension(): ContainerBuilder
    {
        $container = $this->container();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'doctrine';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        return $container;
    }

    /** Not registered in the container: compile() would otherwise run load() a second time on its own. */
    private function extension(): ExtensionInterface&PrependExtensionInterface
    {
        $extension = (new YdbDoctrineBundle())->getContainerExtension();
        $this->assertInstanceOf(PrependExtensionInterface::class, $extension);

        return $extension;
    }
}
