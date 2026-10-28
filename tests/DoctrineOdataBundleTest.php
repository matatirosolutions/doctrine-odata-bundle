<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Matatirosoln\DoctrineOdataBundle\DoctrineOdataBundle;
use Matatirosoln\DoctrineOdataBundle\Middleware\ODataMetadataCacheMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class DoctrineOdataBundleTest extends TestCase
{
    private function containerWithBundleLoaded(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition(\stdClass::class));
        $container->setDefinition('cache.app', new Definition(\stdClass::class));
        $container->register('request_stack', \stdClass::class);

        $bundle = new DoctrineOdataBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension($bundle->getContainerExtension()->getAlias(), []);
        $bundle->getContainerExtension()->load([[]], $container);

        return $container;
    }

    public function testMetadataCacheMiddlewareIsTaggedWithAPriorityAboveDoctrinesOwn(): void
    {
        $container = $this->containerWithBundleLoaded();

        $tags = $container->getDefinition(ODataMetadataCacheMiddleware::class)->getTag('doctrine.middleware');

        $this->assertCount(1, $tags);
        $this->assertSame(DoctrineOdataBundle::METADATA_CACHE_MIDDLEWARE_PRIORITY, $tags[0]['priority']);
        // DoctrineBundle's idle-connection, debug and logging middlewares are all priority 10.
        $this->assertGreaterThan(10, $tags[0]['priority']);
    }

    /**
     * The regression: with no priority the cache middleware was applied after the idle
     * connection middleware, by which point the driver was no longer an ODataDriver.
     */
    public function testDoctrineAppliesTheMetadataCacheMiddlewareBeforeItsOwnMiddlewares(): void
    {
        $container = $this->containerWithBundleLoaded();

        // Stand-in for DoctrineBundle's idle-connection middleware (priority 10).
        $container->setDefinition('doctrine.dbal.idle_connection_middleware', (new Definition(\stdClass::class))
            ->addTag('doctrine.middleware', ['priority' => 10]));

        (new MiddlewaresPass())->process($container);

        $calls = $container->getDefinition('doctrine.dbal.default_connection.configuration')->getMethodCalls();
        $this->assertSame('setMiddlewares', $calls[0][0]);

        $order = array_map(static fn(Reference $r): string => (string) $r, $calls[0][1][0]);

        $this->assertSame(
            [
                ODataMetadataCacheMiddleware::class . '.default',
                'doctrine.dbal.idle_connection_middleware.default',
            ],
            $order,
            'Doctrine applies middlewares in this order; the metadata cache middleware must come first.',
        );
    }
}
