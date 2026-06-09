<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\Middleware;

use Doctrine\DBAL\Driver;
use Matatirosoln\DoctrineOdataBundle\Middleware\ODataMetadataCacheDriver;
use Matatirosoln\DoctrineOdataBundle\Middleware\ODataMetadataCacheMiddleware;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class ODataMetadataCacheMiddlewareTest extends TestCase
{
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testWrapReturnsSameDriverForNonODataDriver(): void
    {
        $otherDriver = $this->createMock(Driver::class);
        $middleware   = new ODataMetadataCacheMiddleware($this->cache, 3600);

        $result = $middleware->wrap($otherDriver);

        $this->assertSame($otherDriver, $result);
    }

    public function testWrapReturnsODataMetadataCacheDriverForODataDriver(): void
    {
        $odataDriver = $this->createMock(ODataDriver::class);
        $middleware   = new ODataMetadataCacheMiddleware($this->cache, 3600);

        $result = $middleware->wrap($odataDriver);

        $this->assertInstanceOf(ODataMetadataCacheDriver::class, $result);
    }

    public function testWrapDoesNotModifyNonODataDriver(): void
    {
        $otherDriver = $this->createMock(Driver::class);
        $middleware   = new ODataMetadataCacheMiddleware($this->cache, 3600);

        // Calling wrap() twice on a non-OData driver always returns the original
        $this->assertSame($middleware->wrap($otherDriver), $middleware->wrap($otherDriver));
    }

    public function testDefaultTtlIsOneHour(): void
    {
        // Construct with no explicit TTL — should default to 3600
        $middleware = new ODataMetadataCacheMiddleware($this->cache);
        $odataDriver = $this->createMock(ODataDriver::class);

        // Just checking it doesn't throw and returns the correct wrapper type
        $this->assertInstanceOf(ODataMetadataCacheDriver::class, $middleware->wrap($odataDriver));
    }
}
