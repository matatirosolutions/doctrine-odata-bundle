<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;
use Psr\SimpleCache\CacheInterface;

/**
 * DBAL middleware that injects a PSR-16 metadata cache into OData connections.
 *
 * Registered automatically by DoctrineOdataBundle and tagged as
 * `doctrine.middleware` so Doctrine Bundle applies it to all connections.
 * The middleware is a no-op for any driver that is not an ODataDriver, so it
 * is safe to register globally even when multiple DBAL connections are in use.
 *
 * When the wrapped driver IS an ODataDriver, connection creation is intercepted
 * to inject the cache instance and TTL into the connection params, where
 * ODataDriver::connect() will pick them up to pass to ODataClient.
 */
class ODataMetadataCacheMiddleware implements Middleware
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttl = 3600,
    ) { }

    public function wrap(Driver $driver): Driver
    {
        if (!$driver instanceof ODataDriver) {
            return $driver;
        }

        return new ODataMetadataCacheDriver($driver, $this->cache, $this->ttl);
    }

}
