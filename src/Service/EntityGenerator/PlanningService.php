<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Matatirosoln\DoctrineOdataBundle\Exception\ConnectionException;
use Matatirosoln\DoctrineOdataBundle\ValueObject\EntityGenerationPlan;
use Matatirosoln\DoctrineOdataDriver\Driver\ODataConnection;

/**
 * Inspects OData $metadata and builds an EntityGenerationPlan.
 *
 * This service is responsible for the metadata-inspection half of entity
 * generation: resolving the OData connection, reading entity set definitions,
 * determining namespaces and file paths, and delegating the actual code
 * rendering to GeneratorService. No files are written here.
 *
 * Use plan() to build and inspect the EntityGenerationPlan before committing
 * to disk, then GeneratorService::writeFile() to write individual files once
 * confirmed.
 */
readonly class PlanningService
{
    public function __construct(
        private Connection       $connection,
        private string           $projectDir,
        private GeneratorService $generator,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Builds an EntityGenerationPlan for the given OData entity set and PHP class name.
     *
     * Validates that the entity set exists in $metadata and resolves all
     * namespace, file path, and rendered source details. No files are written.
     *
     * @param string $entitySet  OData entity set name, e.g. "Users".
     * @param string $className  PHP class name with optional sub-namespace,
     *                           e.g. "User" or "Users\User". Forward slashes
     *                           are accepted and normalised to backslashes.
     *
     * @throws InvalidArgumentException if the entity set is not found in $metadata.
     * @throws ConnectionException       if the OData connection cannot be resolved.
     */
    public function plan(string $entitySet, string $className): EntityGenerationPlan
    {
        $className = str_replace('/', '\\', $className);
        $metadata  = $this->resolveODataConnection()->getMetadata();

        if (!isset($metadata[$entitySet])) {
            throw new InvalidArgumentException(sprintf(
                'Entity set "%s" not found in $metadata. Available sets: %s',
                $entitySet,
                implode(', ', array_keys($metadata)),
            ));
        }

        $entityMeta = $metadata[$entitySet];
        $baseNs     = $this->resolveBaseNamespace();

        // Split "Users\User" → subNs = "Users", shortClass = "User"
        // Split "User"       → subNs = "",      shortClass = "User"
        $parts      = explode('\\', $className);
        $shortClass = array_pop($parts);
        $subNs      = implode('\\', $parts);

        $entityNs   = $baseNs . '\\Entity'     . ($subNs !== '' ? '\\' . $subNs : '');
        $repoNs     = $baseNs . '\\Repository' . ($subNs !== '' ? '\\' . $subNs : '');
        $repoClass  = $shortClass . 'Repository';
        $repoFqcn   = $repoNs . '\\' . $repoClass;
        $entityFqcn = $entityNs . '\\' . $shortClass;

        return new EntityGenerationPlan(
            entitySet:       $entitySet,
            entityClass:     $entityFqcn,
            entityFile:      $this->generator->classToPath($entityFqcn, $baseNs),
            entityCode:      $this->generator->renderEntity($entityNs, $shortClass, $entitySet, $repoFqcn, $entityMeta),
            repositoryClass: $repoFqcn,
            repositoryFile:  $this->generator->classToPath($repoFqcn, $baseNs),
            repositoryCode:  $this->generator->renderRepository($repoNs, $repoClass, $entityFqcn),
            propertyCount:   count($entityMeta['properties']),
            primaryKeyField: $entityMeta['pk'],
            projectDir:      $this->projectDir,
        );
    }

    // -------------------------------------------------------------------------
    // Infrastructure
    // -------------------------------------------------------------------------

    /**
     * Resolves the ODataConnection from the injected DBAL connection.
     *
     * @throws ConnectionException if the connection is not backed by ODataDriver.
     */
    private function resolveODataConnection(): ODataConnection
    {
        try {
            $native = $this->connection->getNativeConnection();
        } catch (\Throwable $e) {
            throw new ConnectionException(
                'PlanningService could not obtain the OData connection: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!$native instanceof ODataConnection) {
            throw new ConnectionException(
                'PlanningService requires a DBAL connection backed by ODataDriver. ' .
                'Check the "connection" setting in doctrine_odata.yaml.',
            );
        }

        return $native;
    }

    /**
     * Reads the project's composer.json to find the PSR-4 base namespace for src/.
     *
     * Falls back to 'App' if composer.json is absent or has no src/ mapping.
     */
    private function resolveBaseNamespace(): string
    {
        $composerPath = $this->projectDir . '/composer.json';

        if (!is_readable($composerPath)) {
            return 'App';
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        $psr4     = $composer['autoload']['psr-4'] ?? [];

        foreach ($psr4 as $ns => $path) {
            $normPath = rtrim(str_replace('\\', '/', $path), '/');
            if ($normPath === 'src') {
                return rtrim($ns, '\\');
            }
        }

        return 'App';
    }
}
