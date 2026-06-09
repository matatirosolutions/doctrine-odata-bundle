<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Psr\SimpleCache\CacheInterface;

/**
 * Wraps ODataDriver to inject a PSR-16 cache and TTL into the connection params
 * before they are passed to ODataDriver::connect().
 *
 * ODataDriver::connect() already reads `metadata_cache` and `metadata_ttl` from
 * its params array and passes them to ODataClient — this driver simply ensures
 * those values are present without requiring the user to configure them manually
 * in doctrine.yaml (which can only hold scalar values).
 */
class ODataMetadataCacheDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly CacheInterface $cache,
        private readonly int $ttl,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        $params['metadata_cache'] = $this->cache;
        $params['metadata_ttl']   = $this->ttl;

        return parent::connect($params);
    }
}
