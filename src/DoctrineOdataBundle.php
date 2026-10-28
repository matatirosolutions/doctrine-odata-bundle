<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle;

use Matatirosoln\DoctrineOdataBundle\Command\GenerateEntityCommand;
use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\GeneratorService;
use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\PlanningService;
use Matatirosoln\DoctrineOdataBundle\Middleware\ODataMetadataCacheMiddleware;
use Matatirosoln\DoctrineOdataBundle\Service\ContainerService;
use Matatirosoln\DoctrineOdataBundle\Service\ScriptService;
use Matatirosoln\DoctrineOdataBundle\Service\ValueListService;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class DoctrineOdataBundle extends AbstractBundle
{
    /**
     * Priority of the metadata cache middleware among Doctrine's `doctrine.middleware` services.
     *
     * Doctrine applies higher priorities first, so each later middleware wraps the previous
     * one. ODataMetadataCacheMiddleware only acts on a bare ODataDriver, so it has to run
     * before everything else; DoctrineBundle's own middlewares (idle connection, debug,
     * logging) use priority 10 and would otherwise wrap the driver first, silently turning
     * the cache into a no-op. Keep this above 10, and give any middleware of your own that
     * wraps the driver a lower priority than this.
     */
    public const int METADATA_CACHE_MIDDLEWARE_PRIORITY = 100;

    /**
     * Defines the bundle's configuration tree.
     *
     * Minimal config (uses defaults — cache.app, 1 hour TTL):
     *
     *   doctrine_odata: ~
     *
     * Custom cache pool and TTL:
     *
     *   doctrine_odata:
     *       metadata_cache:
     *           pool: cache.redis   # any Symfony PSR-6 cache pool
     *           ttl: 7200
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('connection')
                    ->defaultValue('default')
                    ->info('Name of the Doctrine DBAL connection that uses ODataDriver. Maps to the doctrine.dbal.{name}_connection service.')
                ->end()
                ->arrayNode('metadata_cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')
                            ->defaultValue('cache.app')
                            ->info('Symfony cache pool service ID (PSR-6) used to persist OData $metadata across requests.')
                        ->end()
                        ->integerNode('ttl')
                            ->defaultValue(3600)
                            ->info('Cache TTL in seconds. 0 = no expiry. The OData schema rarely changes so a long TTL is appropriate.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $connectionServiceId = sprintf('doctrine.dbal.%s_connection', $config['connection']);
        $cachePool           = $config['metadata_cache']['pool'];
        $ttl                 = $config['metadata_cache']['ttl'];

        $services = $configurator->services();

        // Adapt the Symfony PSR-6 cache pool to the PSR-16 CacheInterface that
        // ODataClient expects. Psr16Cache is part of symfony/cache.
        $services
            ->set('matatirosoln.odata.psr16_cache', Psr16Cache::class)
            ->args([service($cachePool)])
            ->private();

        // Register the DBAL middleware tagged so Doctrine Bundle applies it to
        // all connections automatically. It is a no-op for non-OData drivers.
        $services
            ->set(ODataMetadataCacheMiddleware::class)
            ->args([
                service('matatirosoln.odata.psr16_cache'),
                $ttl,
            ])
            ->tag('doctrine.middleware', ['priority' => self::METADATA_CACHE_MIDDLEWARE_PRIORITY]);

        // FileMaker-specific services — autowireable by type.
        $services
            ->set(ScriptService::class)
            ->args([service($connectionServiceId)]);

        $services
            ->set(ContainerService::class)
            ->args([service($connectionServiceId)]);

        $services
            ->set(ValueListService::class)
            ->args([
                service($connectionServiceId),
                service('request_stack'),
            ]);

        $services
            ->set(GeneratorService::class)
            ->args(['%kernel.project_dir%']);

        $services
            ->set(PlanningService::class)
            ->args([
                service($connectionServiceId),
                '%kernel.project_dir%',
                service(GeneratorService::class),
            ]);

        $services
            ->set(GenerateEntityCommand::class)
            ->args([
                service(PlanningService::class),
                service(GeneratorService::class),
            ])
            ->tag('console.command');
    }
}
