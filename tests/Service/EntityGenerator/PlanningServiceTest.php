<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\Service\EntityGenerator;

use Doctrine\DBAL\Connection;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\GeneratorService;
use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\PlanningService;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlanningServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ODataConnection&MockObject $odataConnection;
    private PlanningService $service;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->connection      = $this->createMock(Connection::class);
        $this->odataConnection = $this->createMock(ODataConnection::class);
        $this->connection->method('getNativeConnection')->willReturn($this->odataConnection);

        // Temporary project directory with a composer.json so namespace resolution works.
        $this->projectDir = sys_get_temp_dir() . '/odata_plan_test_' . uniqid();
        mkdir($this->projectDir . '/src', 0755, true);
        file_put_contents(
            $this->projectDir . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]]),
        );

        $generator     = new GeneratorService($this->projectDir);
        $this->service = new PlanningService($this->connection, $this->projectDir, $generator);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    // -------------------------------------------------------------------------
    // plan() — validation
    // -------------------------------------------------------------------------

    public function testPlanThrowsForUnknownEntitySet(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"Unknown" not found in $metadata');

        $this->service->plan('Unknown', 'User');
    }

    public function testPlanThrowsConnectionExceptionWhenNativeConnectionFails(): void
    {
        $this->connection->method('getNativeConnection')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(ConnectionException::class);
        $this->service->plan('User', 'User');
    }

    public function testPlanThrowsConnectionExceptionForNonODataConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getNativeConnection')->willReturn(new \stdClass());
        $generator = new GeneratorService($this->projectDir);
        $service   = new PlanningService($connection, $this->projectDir, $generator);

        $this->expectException(ConnectionException::class);
        $service->plan('User', 'User');
    }

    // -------------------------------------------------------------------------
    // plan() — namespace and path resolution
    // -------------------------------------------------------------------------

    public function testPlanResolvesEntityClassFromComposerJson(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame('App\\Entity\\User', $plan->entityClass);
    }

    public function testPlanResolvesRepositoryClass(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame('App\\Repository\\UserRepository', $plan->repositoryClass);
    }

    public function testPlanWithSubNamespaceResolvesCorrectClasses(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'Users\\User');

        $this->assertSame('App\\Entity\\Users\\User',                $plan->entityClass);
        $this->assertSame('App\\Repository\\Users\\UserRepository',  $plan->repositoryClass);
    }

    public function testPlanNormalisesForwardSlashesToBackslashes(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'Users/User');

        $this->assertSame('App\\Entity\\Users\\User', $plan->entityClass);
    }

    public function testPlanEntityFilePathIsUnderSrcEntity(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame($this->projectDir . '/src/Entity/User.php', $plan->entityFile);
    }

    public function testPlanRepositoryFilePathIsUnderSrcRepository(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame($this->projectDir . '/src/Repository/UserRepository.php', $plan->repositoryFile);
    }

    public function testPlanReturnsCorrectPropertyCount(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame(count($this->sampleMetadata()['User']['properties']), $plan->propertyCount);
    }

    public function testPlanReturnsCorrectPrimaryKeyField(): void
    {
        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame('__pk_UserID', $plan->primaryKeyField);
    }

    public function testPlanFallsBackToAppNamespaceWithoutComposerJson(): void
    {
        unlink($this->projectDir . '/composer.json');

        $this->odataConnection->method('getMetadata')->willReturn($this->sampleMetadata());

        $plan = $this->service->plan('User', 'User');

        $this->assertSame('App\\Entity\\User', $plan->entityClass);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>}>
     */
    private static function sampleMetadata(): array
    {
        return [
            'User' => [
                'pk'         => '__pk_UserID',
                'properties' => [
                    '__pk_UserID' => ['type' => 'Edm.Guid',           'nullable' => false],
                    'Name'        => ['type' => 'Edm.String',         'nullable' => false],
                    'City'        => ['type' => 'Edm.String',         'nullable' => true],
                    'Age'         => ['type' => 'Edm.Int32',          'nullable' => false],
                    'CreatedAt'   => ['type' => 'Edm.DateTimeOffset', 'nullable' => true],
                    'Photo'       => ['type' => 'Edm.Stream',         'nullable' => true],
                    'First_Name'  => ['type' => 'Edm.String',         'nullable' => false],
                ],
            ],
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
