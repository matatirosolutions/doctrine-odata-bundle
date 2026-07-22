<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Matatirosoln\DoctrineOdataBundle\Service\ContainerService;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use Matatirosoln\SqlToOdata\Support\KeyValue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContainerServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ODataConnection&MockObject $odataConnection;
    private ODataClient&MockObject $client;
    private ContainerService $service;

    protected function setUp(): void
    {
        $this->client          = $this->createMock(ODataClient::class);
        $this->odataConnection = $this->createMock(ODataConnection::class);
        $this->connection      = $this->createMock(Connection::class);

        $this->odataConnection->method('getClient')->willReturn($this->client);
        $this->connection->method('getNativeConnection')->willReturn($this->odataConnection);

        $this->service = new ContainerService($this->connection);
    }

    // -------------------------------------------------------------------------
    // resolveNumericKey — happy path
    // -------------------------------------------------------------------------

    public function testUploadWithQuotedKeyResolvesNumericKeyAndCallsPatchBinary(): void
    {
        $this->client->method('fetchMetadata')->willReturn([
            'entities'   => ['Document' => ['pk' => '__pk_DocumentId', 'properties' => []]],
            'valueLists' => [],
        ]);

        $this->client->method('get')->willReturn([
            'value' => [
                ['@id' => 'Document(42)', 'Name' => 'test.pdf'],
            ],
        ]);

        $this->client
            ->expects($this->once())
            ->method('patchBinary')
            ->with(
                'Document',
                $this->callback(fn(KeyValue $k) => $k->value === '42' && $k->quoted === false),
                'Attachment',
                'binary-content',
                'application/pdf',
                $this->isType('int'),
            );

        $this->service->upload(
            'Document',
            new KeyValue('uuid-string', quoted: true),
            'Attachment',
            'binary-content',
            'application/pdf',
        );
    }

    // -------------------------------------------------------------------------
    // resolveNumericKey — entity not found
    // -------------------------------------------------------------------------

    public function testUploadThrowsWhenEntityNotFound(): void
    {
        $this->client->method('fetchMetadata')->willReturn([
            'entities'   => ['Document' => ['pk' => '__pk_DocumentId', 'properties' => []]],
            'valueLists' => [],
        ]);

        $this->client->method('get')->willReturn(['value' => []]);

        $this->expectException(ODataDriverException::class);
        $this->expectExceptionMessage('Could not resolve numeric OData key');

        $this->service->upload(
            'Document',
            new KeyValue('missing-uuid', quoted: true),
            'Attachment',
            'content',
            'application/pdf',
        );
    }

    // -------------------------------------------------------------------------
    // resolveNumericKey — unexpected @id format
    // -------------------------------------------------------------------------

    public function testUploadThrowsWhenAtIdHasNoNumericKey(): void
    {
        $this->client->method('fetchMetadata')->willReturn([
            'entities'   => ['Document' => ['pk' => '__pk_DocumentId', 'properties' => []]],
            'valueLists' => [],
        ]);

        $this->client->method('get')->willReturn([
            'value' => [['@id' => 'Document(not-a-number)']],
        ]);

        $this->expectException(ODataDriverException::class);
        $this->expectExceptionMessage('Unexpected @id format');

        $this->service->upload(
            'Document',
            new KeyValue('some-uuid', quoted: true),
            'Attachment',
            'content',
            'application/pdf',
        );
    }

    // -------------------------------------------------------------------------
    // quoted: false bypasses resolution
    // -------------------------------------------------------------------------

    public function testUploadWithUnquotedKeySkipsResolution(): void
    {
        $this->client->expects($this->never())->method('fetchMetadata');
        $this->client->expects($this->never())->method('get');

        $this->client
            ->expects($this->once())
            ->method('patchBinary')
            ->with(
                'Document',
                $this->callback(fn(KeyValue $k) => $k->value === '26' && $k->quoted === false),
                'Attachment',
                'content',
                'application/pdf',
                $this->isType('int'),
            );

        $this->service->upload(
            'Document',
            new KeyValue('26', quoted: false),
            'Attachment',
            'content',
            'application/pdf',
        );
    }
}
