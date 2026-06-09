<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataBundle\Exception\ScriptException;
use Matatirosoln\DoctrineOdataBundle\Service\ScriptService;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use Matatirosoln\DoctrineOdataDriver\Http\ODataClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ScriptServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ODataConnection&MockObject $odataConnection;
    private ODataClient&MockObject $client;
    private ScriptService $service;

    protected function setUp(): void
    {
        $this->client          = $this->createMock(ODataClient::class);
        $this->odataConnection = $this->createMock(ODataConnection::class);
        $this->connection      = $this->createMock(Connection::class);

        $this->odataConnection->method('getClient')->willReturn($this->client);
        $this->connection->method('getNativeConnection')->willReturn($this->odataConnection);

        $this->service = new ScriptService($this->connection);
    }

    // -------------------------------------------------------------------------
    // Script name validation
    // -------------------------------------------------------------------------

    public function testScriptNameWithAtSignThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('@');

        $this->service->run('Bad@Name');
    }

    public function testScriptNameWithAmpersandThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('&');

        $this->service->run('Bad&Name');
    }

    public function testScriptNameWithSlashThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/');

        $this->service->run('Bad/Name');
    }

    public function testScriptNameStartingWithDigitThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('digit');

        $this->service->run('1BadName');
    }

    public function testValidScriptNameDoesNotThrowOnValidation(): void
    {
        $this->client->method('runScript')->willReturn([
            'scriptResult' => ['code' => 0, 'resultParameter' => ''],
        ]);

        // No exception should be thrown for a clean name
        $result = $this->service->run('ValidScriptName');

        $this->assertSame(0, $result['code']);
    }

    // -------------------------------------------------------------------------
    // Parameter handling
    // -------------------------------------------------------------------------

    public function testArrayParameterIsJsonEncoded(): void
    {
        $this->client
            ->expects($this->once())
            ->method('runScript')
            ->with('MyScript', '{"key":"value","count":3}')
            ->willReturn(['scriptResult' => ['code' => 0, 'resultParameter' => '']]);

        $this->service->run('MyScript', ['key' => 'value', 'count' => 3]);
    }

    public function testStringParameterIsPassedAsIs(): void
    {
        $this->client
            ->expects($this->once())
            ->method('runScript')
            ->with('MyScript', 'raw-string-param')
            ->willReturn(['scriptResult' => ['code' => 0, 'resultParameter' => '']]);

        $this->service->run('MyScript', 'raw-string-param');
    }

    public function testNullParameterIsPassedAsNull(): void
    {
        $this->client
            ->expects($this->once())
            ->method('runScript')
            ->with('MyScript', null)
            ->willReturn(['scriptResult' => ['code' => 0, 'resultParameter' => '']]);

        $this->service->run('MyScript', null);
    }

    public function testDefaultParameterIsNull(): void
    {
        $this->client
            ->expects($this->once())
            ->method('runScript')
            ->with('MyScript', null)
            ->willReturn(['scriptResult' => ['code' => 0, 'resultParameter' => '']]);

        $this->service->run('MyScript');
    }

    // -------------------------------------------------------------------------
    // Return value
    // -------------------------------------------------------------------------

    public function testSuccessfulRunReturnsCodeZeroAndResultParameter(): void
    {
        $this->client->method('runScript')->willReturn([
            'scriptResult' => ['code' => 0, 'resultParameter' => 'ok'],
        ]);

        $result = $this->service->run('MyScript');

        $this->assertSame(0,    $result['code']);
        $this->assertSame('ok', $result['resultParameter']);
    }

    // -------------------------------------------------------------------------
    // ScriptException on non-zero result code
    // -------------------------------------------------------------------------

    public function testNonZeroScriptCodeThrowsScriptException(): void
    {
        $this->client->method('runScript')->willReturn([
            'scriptResult' => ['code' => 500, 'resultParameter' => 'something went wrong'],
        ]);

        $this->expectException(ScriptException::class);

        $this->service->run('MyScript');
    }

    public function testScriptExceptionContainsScriptName(): void
    {
        $this->client->method('runScript')->willReturn([
            'scriptResult' => ['code' => 1, 'resultParameter' => ''],
        ]);

        try {
            $this->service->run('FailingScript');
            $this->fail('ScriptException was not thrown');
        } catch (ScriptException $e) {
            $this->assertSame('FailingScript', $e->scriptName);
        }
    }

    public function testScriptExceptionContainsResultCode(): void
    {
        $this->client->method('runScript')->willReturn([
            'scriptResult' => ['code' => 42, 'resultParameter' => ''],
        ]);

        try {
            $this->service->run('FailingScript');
            $this->fail('ScriptException was not thrown');
        } catch (ScriptException $e) {
            $this->assertSame(42, $e->scriptCode);
        }
    }

    public function testScriptExceptionContainsResultParameter(): void
    {
        $this->client->method('runScript')->willReturn([
            'scriptResult' => ['code' => 1, 'resultParameter' => 'error details'],
        ]);

        try {
            $this->service->run('FailingScript');
            $this->fail('ScriptException was not thrown');
        } catch (ScriptException $e) {
            $this->assertSame('error details', $e->resultParameter);
        }
    }

    // -------------------------------------------------------------------------
    // Connection error handling
    // -------------------------------------------------------------------------

    public function testConnectionExceptionWhenNativeConnectionFails(): void
    {
        $this->connection->method('getNativeConnection')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(ConnectionException::class);

        $this->service->run('AnyScript');
    }

    public function testConnectionExceptionWhenConnectionIsNotOData(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getNativeConnection')->willReturn(new \stdClass());
        $service = new ScriptService($connection);

        $this->expectException(ConnectionException::class);

        $service->run('AnyScript');
    }
}
