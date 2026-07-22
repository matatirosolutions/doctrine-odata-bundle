<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator;

use RuntimeException;

/**
 * Renders Doctrine entity and repository PHP source files and writes them to disk.
 *
 * This service has no knowledge of OData connections, namespaces, or project
 * structure — those concerns belong to PlanningService, which calls the public
 * render methods here after resolving all the relevant details.
 */
readonly class GeneratorService
{
    /**
     * Maps OData EDM primitive types to PHP type hints used in generated code.
     */
    private const array PHP_TYPES = [
        'Edm.String'         => 'string',
        'Edm.Int16'          => 'int',
        'Edm.Int32'          => 'int',
        'Edm.Int64'          => 'int',
        'Edm.Decimal'        => 'float',
        'Edm.Double'         => 'float',
        'Edm.Single'         => 'float',
        'Edm.Boolean'        => 'bool',
        'Edm.Date'           => '\DateTimeImmutable',
        'Edm.DateTimeOffset' => '\DateTimeImmutable',
        'Edm.TimeOfDay'      => '\DateTimeImmutable',
        'Edm.Binary'         => 'string',
        'Edm.Stream'         => 'string',
        'Edm.Guid'           => 'string',
    ];

    /**
     * Maps OData EDM primitive types to Doctrine\DBAL\Types\Types constant names.
     *
     * Values are the constant *name* (e.g. 'STRING'), not the runtime value
     * (e.g. 'string'), so the generator can emit Types::STRING in the output file.
     */
    private const array DBAL_TYPES = [
        'Edm.String'         => 'STRING',
        'Edm.Int16'          => 'SMALLINT',
        'Edm.Int32'          => 'INTEGER',
        'Edm.Int64'          => 'BIGINT',
        'Edm.Decimal'        => 'DECIMAL',
        'Edm.Double'         => 'FLOAT',
        'Edm.Single'         => 'FLOAT',
        'Edm.Boolean'        => 'BOOLEAN',
        'Edm.Date'           => 'DATE_IMMUTABLE',
        'Edm.DateTimeOffset' => 'DATETIME_IMMUTABLE',
        'Edm.TimeOfDay'      => 'TIME_IMMUTABLE',
        'Edm.Binary'         => 'BLOB',
        'Edm.Stream'         => 'BLOB',
        'Edm.Guid'           => 'GUID',
    ];

    public function __construct(private string $projectDir) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Renders the entity PHP source.
     *
     * @param array{pk: string, properties: array<string, array{type: string, nullable: bool}>} $entityMeta
     */
    public function renderEntity(
        string $namespace,
        string $className,
        string $entitySet,
        string $repoFqcn,
        array  $entityMeta,
    ): string {
        $pkField      = $entityMeta['pk'];
        $pkEdm        = $entityMeta['properties'][$pkField]['type'] ?? 'Edm.String';
        $pkType       = $this->phpType($pkEdm, false);
        $pkColumnAttr = $this->buildColumnAttr($pkField, $pkEdm, false);
        $repoShort    = substr($repoFqcn, strrpos($repoFqcn, '\\') + 1);

        // Primary key — private(set) so the repository controls hydration.
        $properties = <<<PROP
    #[ORM\Id]
    {$pkColumnAttr}
    public private(set) {$pkType} \$id {
        get => \$this->id;
        set => \$this->id = \$value;
    }
PROP;

        foreach ($entityMeta['properties'] as $field => $meta) {
            if ($field === $pkField) {
                continue;
            }

            $nullable   = $meta['nullable'];
            $edmType    = $meta['type'];
            $phpType    = $this->phpType($edmType, $nullable);
            $prop       = $this->fieldToProperty($field);
            $columnAttr = $this->buildColumnAttr($field, $edmType, $nullable);
            $default    = $nullable ? ' = null' : '';
            $rhs        = $this->setterRhs($edmType, $nullable);

            if (in_array($edmType, ['Edm.Binary', 'Edm.Stream'], true)) {
                $properties .= "\n\n    /** Container field — OData responses does not map this field; use Matatirosoln\DoctrineOdataBundle\Service\ContainerService to download/upload. */";
            }

            $properties .= <<<PROP


    {$columnAttr}
    public {$phpType} \${$prop}{$default} {
        get => \$this->{$prop};
        set => \$this->{$prop} = {$rhs};
    }
PROP;
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

use {$repoFqcn};
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: '{$entitySet}')]
#[ORM\Entity(repositoryClass: {$repoShort}::class)]
class {$className}
{
{$properties}

    public function __construct({$pkType} \$id)
    {
        \$this->id = \$id;
    }
}
PHP;
    }

    public function renderRepository(
        string $namespace,
        string $className,
        string $entityFqcn,
    ): string {
        $shortEntity = substr($entityFqcn, strrpos($entityFqcn, '\\') + 1);

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

use {$entityFqcn};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<{$shortEntity}>
 */
class {$className} extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry \$registry)
    {
        parent::__construct(\$registry, {$shortEntity}::class);
    }
}
PHP;
    }

    /**
     * Converts a fully-qualified class name into an absolute file path under src/.
     *
     * App\Entity\Users\User → {projectDir}/src/Entity/Users/User.php
     */
    public function classToPath(string $fqcn, string $baseNs): string
    {
        $relative = str_replace('\\', '/', substr($fqcn, strlen($baseNs) + 1));
        return $this->projectDir . '/src/' . $relative . '.php';
    }

    /**
     * Writes content to an absolute path, creating parent directories as needed.
     *
     * Does not check for existing files — the caller is responsible for
     * deciding whether to overwrite. Throws on filesystem errors.
     *
     * @throws RuntimeException if the directory cannot be created or the file cannot be written.
     */
    public function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory: %s', $dir));
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write file: %s', $path));
        }
    }

    // -------------------------------------------------------------------------
    // Type helpers
    // -------------------------------------------------------------------------

    private function phpType(string $edmType, bool $nullable): string
    {
        $base = self::PHP_TYPES[$edmType] ?? 'string';
        return $nullable ? '?' . $base : $base;
    }

    /**
     * Builds a #[ORM\Column(...)] attribute string for the generated entity.
     *
     * The type argument uses the Types:: constant form (e.g. Types::STRING)
     * rather than a quoted string literal.
     */
    private function buildColumnAttr(string $fieldName, string $edmType, bool $nullable): string
    {
        $constant = self::DBAL_TYPES[$edmType] ?? 'STRING';

        $parts = [
            "name: '" . $fieldName . "'",
            'type: Types::' . $constant,
        ];

        if ($edmType === 'Edm.String') {
            $parts[] = 'length: 255';
        }

        if ($nullable) {
            $parts[] = 'nullable: true';
        }

        return '#[ORM\Column(' . implode(', ', $parts) . ')]';
    }

    /**
     * Returns the right-hand side of a property hook setter for the given EDM type.
     *
     * String types get trim() applied; nullable strings get a null-safe variant.
     * All other types use a direct $value assignment.
     */
    private function setterRhs(string $edmType, bool $nullable): string
    {
        if ($edmType === 'Edm.String') {
            return $nullable ? '$value !== null ? trim($value) : null' : 'trim($value)';
        }

        return '$value';
    }

    // -------------------------------------------------------------------------
    // Name conversion
    // -------------------------------------------------------------------------

    /**
     * Converts an OData field name to a PHP camelCase property name.
     *
     * Examples:
     *   __pk_UserID → pkUserID
     *   Name        → name
     *   City        → city
     *   FirstName   → firstName
     *   ZIP_Code    → zipCode
     */
    private function fieldToProperty(string $field): string
    {
        $field = ltrim($field, '_');
        $words = array_map('ucfirst', explode('_', $field));

        return lcfirst(implode('', $words));
    }
}
