<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\ValueObject;

/**
 * Immutable value object describing what the entity generator will produce.
 *
 * Returned by EntityGeneratorService::plan() before any files are written,
 * so the caller (typically the CLI command) can inspect and confirm the plan
 * before committing to disk.
 */
readonly class EntityGenerationPlan
{
    public function __construct(
        /** The OData entity set name, e.g. "Users". */
        public string $entitySet,

        /** Fully-qualified entity class name, e.g. "App\Entity\Users\User". */
        public string $entityClass,

        /** Absolute path to the entity file to be written. */
        public string $entityFile,

        /** Rendered PHP source for the entity class. */
        public string $entityCode,

        /** Fully-qualified repository class name, e.g. "App\Repository\Users\UserRepository". */
        public string $repositoryClass,

        /** Absolute path to the repository file to be written. */
        public string $repositoryFile,

        /** Rendered PHP source for the repository class. */
        public string $repositoryCode,

        /** Number of properties (including the primary key) in the entity set. */
        public int $propertyCount,

        /** OData field name of the primary key, e.g. "__pk_UserID". */
        public string $primaryKeyField,

        /** Project root; used internally for display helpers. */
        private string $projectDir,
    ) {
    }

    /**
     * Returns a path relative to the project root for display purposes.
     *
     * Absolute paths outside the project root are returned unchanged.
     */
    public function relativePath(string $absolutePath): string
    {
        return str_starts_with($absolutePath, $this->projectDir)
            ? substr($absolutePath, strlen($this->projectDir) + 1)
            : $absolutePath;
    }
}
