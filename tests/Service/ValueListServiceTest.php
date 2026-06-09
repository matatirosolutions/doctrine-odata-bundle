<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataBundle\Service\ValueListService;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class ValueListServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ODataConnection&MockObject $odataConnection;
    private ODataClient&MockObject $client;
    private RequestStack&MockObject $requestStack;
    private SessionInterface&MockObject $session;
    private ValueListService $service;

    protected function setUp(): void
    {
        $this->client          = $this->createMock(ODataClient::class);
        $this->odataConnection = $this->createMock(ODataConnection::class);
        $this->connection      = $this->createMock(Connection::class);
        $this->requestStack    = $this->createMock(RequestStack::class);
        $this->session         = $this->createMock(SessionInterface::class);

        $this->odataConnection->method('getClient')->willReturn($this->client);
        $this->connection->method('getNativeConnection')->willReturn($this->odataConnection);

        $this->service = new ValueListService($this->connection, $this->requestStack);
    }

    // -------------------------------------------------------------------------
    // names()
    // -------------------------------------------------------------------------

    public function testNamesReturnsValueListNamesFromMetadata(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status', 'Priority', 'Users']);

        $this->assertSame(['Status', 'Priority', 'Users'], $this->service->names());
    }

    public function testNamesReturnsEmptyArrayWhenNoValueLists(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn([]);

        $this->assertSame([], $this->service->names());
    }

    // -------------------------------------------------------------------------
    // get() — OData fetch and entry parsing
    // -------------------------------------------------------------------------

    public function testGetThrowsForUnknownListName(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"Unknown"');

        $this->service->get('Unknown');
    }

    public function testGetFetchesStaticListFromOData(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->method('get')
            ->with('FileMaker_ValueList_Status')
            ->willReturn(['value' => [
                ['FirstValues' => 'Active'],
                ['FirstValues' => 'Inactive'],
            ]]);

        $result = $this->service->get('Status');

        $this->assertSame(['Active' => 'Active', 'Inactive' => 'Inactive'], $result);
    }

    public function testGetFetchesDynamicListFromOData(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Users']);
        $this->withNoSession();

        $this->client->method('get')
            ->with('FileMaker_ValueList_Users')
            ->willReturn(['value' => [
                ['FirstValues' => 'uuid-001', 'SecondValues' => 'Alice Smith'],
                ['FirstValues' => 'uuid-002', 'SecondValues' => 'Bob Jones'],
            ]]);

        $result = $this->service->get('Users');

        $this->assertSame([
            'Alice Smith' => 'uuid-001',
            'Bob Jones'   => 'uuid-002',
        ], $result);
    }

    public function testGetSkipsRowsWithNoFirstValues(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->method('get')->willReturn(['value' => [
            ['FirstValues' => 'Active'],
            [],   // malformed row — no FirstValues
            ['FirstValues' => 'Inactive'],
        ]]);

        $result = $this->service->get('Status');

        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // get() — request-scoped cache
    // -------------------------------------------------------------------------

    public function testGetOnlyCallsODataOnceWithinSameRequest(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->expects($this->once())
            ->method('get')
            ->willReturn(['value' => [['FirstValues' => 'Active']]]);

        $this->service->get('Status');
        $this->service->get('Status'); // second call — should hit request cache
    }

    public function testGetReturnsSameResultFromRequestCache(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->method('get')->willReturn(['value' => [['FirstValues' => 'Active']]]);

        $first  = $this->service->get('Status');
        $second = $this->service->get('Status');

        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // get() — session cache
    // -------------------------------------------------------------------------

    public function testGetReadsFromSessionCacheOnSecondRequest(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withSession();

        // Session already has the data (simulates a second request after first populated it)
        $this->session->method('get')
            ->with('_odata_vl_Status')
            ->willReturn(['Active' => 'Active', 'Inactive' => 'Inactive']);

        // OData client should NOT be called since session has the data
        $this->client->expects($this->never())->method('get');

        $result = $this->service->get('Status');

        $this->assertSame(['Active' => 'Active', 'Inactive' => 'Inactive'], $result);
    }

    public function testGetStoresResultInSessionOnODataFetch(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withSession();

        $this->session->method('get')->with('_odata_vl_Status')->willReturn(null);

        $this->client->method('get')->willReturn(['value' => [
            ['FirstValues' => 'Active'],
        ]]);

        $this->session->expects($this->once())
            ->method('set')
            ->with('_odata_vl_Status', ['Active' => 'Active']);

        $this->service->get('Status');
    }

    public function testGetWorksBypassingSessionWhenNoSessionExists(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->method('get')->willReturn(['value' => [['FirstValues' => 'Active']]]);

        $result = $this->service->get('Status');

        $this->assertSame(['Active' => 'Active'], $result);
    }

    // -------------------------------------------------------------------------
    // choices()
    // -------------------------------------------------------------------------

    public function testChoicesIsAliasForGet(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->method('get')->willReturn(['value' => [['FirstValues' => 'Active']]]);

        $this->assertSame($this->service->get('Status'), $this->service->choices('Status'));
    }

    // -------------------------------------------------------------------------
    // clearCache()
    // -------------------------------------------------------------------------

    public function testClearCacheByNameForcesODataRefetchOnNextCall(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withNoSession();

        $this->client->expects($this->exactly(2))
            ->method('get')
            ->willReturn(['value' => [['FirstValues' => 'Active']]]);

        $this->service->get('Status');     // populates request cache
        $this->service->clearCache('Status'); // clears it
        $this->service->get('Status');     // must re-fetch
    }

    public function testClearCacheByNameRemovesFromSession(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withSession();

        $this->session->method('get')->willReturn(null);
        $this->client->method('get')->willReturn(['value' => []]);

        $this->session->expects($this->once())
            ->method('remove')
            ->with('_odata_vl_Status');

        $this->service->clearCache('Status');
    }

    public function testClearAllCachesForcesRefetchForAllLists(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status', 'Priority']);
        $this->withNoSession();

        $this->client->expects($this->exactly(4)) // 2 initial + 2 after clear
            ->method('get')
            ->willReturn(['value' => []]);

        $this->service->get('Status');
        $this->service->get('Priority');
        $this->service->clearCache();
        $this->service->get('Status');
        $this->service->get('Priority');
    }

    public function testClearAllCachesSweeopsSessionKeys(): void
    {
        $this->odataConnection->method('getValueLists')->willReturn(['Status']);
        $this->withSession();

        $this->session->method('get')->willReturn(null);
        $this->client->method('get')->willReturn(['value' => []]);

        // Session::all() returns the full bag; the service sweeps _odata_vl_ keys
        $this->session->method('all')->willReturn([
            '_odata_vl_Status'   => [],
            '_odata_vl_Priority' => [],
            'user_id'            => 42,
        ]);

        $this->session->expects($this->exactly(2))
            ->method('remove')
            ->with($this->logicalOr(
                '_odata_vl_Status',
                '_odata_vl_Priority',
            ));

        $this->service->clearCache();
    }

    // -------------------------------------------------------------------------
    // Connection error handling
    // -------------------------------------------------------------------------

    public function testConnectionExceptionWhenNativeConnectionFails(): void
    {
        $this->connection->method('getNativeConnection')
            ->willThrowException(new \RuntimeException('gone'));

        $this->expectException(ConnectionException::class);

        $this->service->names();
    }

    public function testConnectionExceptionWhenConnectionIsNotOData(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getNativeConnection')->willReturn(new \stdClass());
        $service = new ValueListService($connection, $this->requestStack);

        $this->expectException(ConnectionException::class);

        $service->names();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function withSession(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('hasSession')->willReturn(true);
        $request->method('getSession')->willReturn($this->session);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }

    private function withNoSession(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);
    }
}
