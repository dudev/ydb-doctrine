<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Driver\YdbDriver;
use Dudev\YdbDoctrine\ORM\EntityManager;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Optional Symfony wiring; symfony/* is only a dev dependency, the class is never loaded outside Symfony.
 * Flex registers it in bundles.php on install (it guesses `<last PSR-4 segment>Bundle` in src/).
 */
class YdbDoctrineBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('entity_manager')
                    ->defaultValue('default')
                    ->info('Name of the entity manager to decorate, null to leave all of them alone.')
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'dbal' => ['driver_schemes' => ['ydb' => YdbDriver::class]],
            ]);
        }
    }

    /** @param array{entity_manager: string|null} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (null === $config['entity_manager']) {
            return;
        }

        // IGNORE_ON_INVALID_REFERENCE: no ORM (or no such manager) just means nothing to decorate.
        $container->services()
            ->set(EntityManager::class)
            ->decorate(
                sprintf('doctrine.orm.%s_entity_manager', $config['entity_manager']),
                null,
                0,
                ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
            )
            ->args([service('.inner')]);
    }
}
