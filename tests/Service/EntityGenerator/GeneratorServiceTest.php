<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\Service\EntityGenerator;

use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\GeneratorService;
use PHPUnit\Framework\TestCase;

class GeneratorServiceTest extends TestCase
{
    private GeneratorService $service;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/odata_gen_test_' . uniqid();
        mkdir($this->projectDir . '/src', 0755, true);

        $this->service = new GeneratorService($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    // -------------------------------------------------------------------------
    // renderEntity() — primary key
    // -------------------------------------------------------------------------

    public function testPrimaryKeyPropertyIsAlwaysNamedId(): void
    {
        $code = $this->renderEntity();

        $this->assertStringContainsString('private(set) string $id', $code);
    }

    public function testPrimaryKeyHasOrmIdAttribute(): void
    {
        $code = $this->renderEntity();

        $this->assertStringContainsString('#[ORM\Id]', $code);
    }

    public function testPrimaryKeyFieldNamePreservedInColumnAttribute(): void
    {
        $code = $this->renderEntity();

        $this->assertStringContainsString("name: '__pk_UserID'", $code);
    }

    public function testPrimaryKeyHasPropertyHooks(): void
    {
        $code = $this->renderEntity();

        $this->assertStringContainsString('get => $this->id;', $code);
        $this->assertStringContainsString('set => $this->id = $value;', $code);
    }

    public function testConstructorInjectsId(): void
    {
        $code = $this->renderEntity();

        $this->assertStringContainsString('public function __construct(string $id)', $code);
        $this->assertStringContainsString('$this->id = $id;', $code);
    }

    // -------------------------------------------------------------------------
    // renderEntity() — column attributes use Types:: constants
    // -------------------------------------------------------------------------

    public function testTypesConstantsUsedInsteadOfStringLiterals(): void
    {
        $code = $this->renderEntity();

        $this->assertStringContainsString('Types::', $code);
        $this->assertStringNotContainsString("type: 'guid'",    $code);
        $this->assertStringNotContainsString("type: 'string'",  $code);
        $this->assertStringNotContainsString("type: 'integer'", $code);
    }

    public function testGuidPkUsesTypesGuid(): void
    {
        $this->assertStringContainsString('Types::GUID', $this->renderEntity());
    }

    public function testStringFieldUsesTypesString(): void
    {
        $this->assertStringContainsString('Types::STRING', $this->renderEntity());
    }

    public function testIntFieldUsesTypesInteger(): void
    {
        $this->assertStringContainsString('Types::INTEGER', $this->renderEntity());
    }

    public function testDateTimeFieldUsesTypesDatetimeImmutable(): void
    {
        $this->assertStringContainsString('Types::DATETIME_IMMUTABLE', $this->renderEntity());
    }

    public function testTypesImportIsPresent(): void
    {
        $this->assertStringContainsString('use Doctrine\DBAL\Types\Types;', $this->renderEntity());
    }

    // -------------------------------------------------------------------------
    // renderEntity() — property types and hooks
    // -------------------------------------------------------------------------

    public function testStringFieldHasTrimInSetter(): void
    {
        $this->assertStringContainsString('trim($value)', $this->renderEntity());
    }

    public function testNullableFieldHasNullablePhpType(): void
    {
        // City is nullable Edm.String → ?string
        $this->assertStringContainsString('?string $city', $this->renderEntity());
    }

    public function testNullableStringSetterIsNullSafe(): void
    {
        $this->assertStringContainsString('$value !== null ? trim($value) : null', $this->renderEntity());
    }

    public function testNullableFieldInitialisedToNull(): void
    {
        $this->assertStringContainsString('= null {', $this->renderEntity());
    }

    public function testNonStringSetterUsesDirectAssignment(): void
    {
        // Age is Edm.Int32 — setter should be "set => $this->age = $value;"
        $this->assertStringContainsString('$this->age = $value;', $this->renderEntity());
    }

    public function testDateTimeFieldHasDateTimeImmutablePhpType(): void
    {
        $this->assertStringContainsString('\DateTimeImmutable', $this->renderEntity());
    }

    // -------------------------------------------------------------------------
    // renderEntity() — field name to property name conversion
    // -------------------------------------------------------------------------

    public function testUnderscoreSeparatedFieldNameIsCamelCased(): void
    {
        // First_Name → firstName
        $this->assertStringContainsString('$firstName', $this->renderEntity());
    }

    public function testSimpleFieldNameIsLowercased(): void
    {
        // Name → name
        $this->assertStringContainsString('$name', $this->renderEntity());
    }

    // -------------------------------------------------------------------------
    // renderEntity() — container fields
    // -------------------------------------------------------------------------

    public function testStreamFieldHasContainerDocComment(): void
    {
        $this->assertStringContainsString('ContainerService', $this->renderEntity());
    }

    public function testStreamFieldUsesTypesBlob(): void
    {
        $this->assertStringContainsString('Types::BLOB', $this->renderEntity());
    }

    // -------------------------------------------------------------------------
    // renderEntity() — string column includes length
    // -------------------------------------------------------------------------

    public function testStringColumnIncludesLength255(): void
    {
        $this->assertStringContainsString('length: 255', $this->renderEntity());
    }

    // -------------------------------------------------------------------------
    // renderRepository()
    // -------------------------------------------------------------------------

    public function testRepositoryExtendsServiceEntityRepository(): void
    {
        $this->assertStringContainsString(
            'class UserRepository extends ServiceEntityRepository',
            $this->renderRepository(),
        );
    }

    public function testRepositoryHasCorrectEntityInGenericType(): void
    {
        $this->assertStringContainsString('@extends ServiceEntityRepository<User>', $this->renderRepository());
    }

    public function testRepositoryConstructorPassesEntityClassToParent(): void
    {
        $this->assertStringContainsString(
            'parent::__construct($registry, User::class)',
            $this->renderRepository(),
        );
    }

    public function testRepositoryImportsEntityClass(): void
    {
        $this->assertStringContainsString('use App\\Entity\\User;', $this->renderRepository());
    }

    // -------------------------------------------------------------------------
    // writeFile()
    // -------------------------------------------------------------------------

    public function testWriteFileCreatesFile(): void
    {
        $path = $this->projectDir . '/src/Test.php';

        $this->service->writeFile($path, '<?php // test');

        $this->assertFileExists($path);
        $this->assertSame('<?php // test', file_get_contents($path));
    }

    public function testWriteFileCreatesParentDirectories(): void
    {
        $path = $this->projectDir . '/src/Deep/Nested/Dir/Test.php';

        $this->service->writeFile($path, '<?php // test');

        $this->assertFileExists($path);
    }

    public function testWriteFileOverwritesExistingFile(): void
    {
        $path = $this->projectDir . '/src/Test.php';
        file_put_contents($path, '<?php // original');

        $this->service->writeFile($path, '<?php // updated');

        $this->assertSame('<?php // updated', file_get_contents($path));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Renders the sample entity and returns the generated source code.
     */
    private function renderEntity(): string
    {
        $meta = $this->sampleMetadata()['User'];

        return $this->service->renderEntity(
            'App\\Entity',
            'User',
            'User',
            'App\\Repository\\UserRepository',
            $meta,
        );
    }

    /**
     * Renders the sample repository and returns the generated source code.
     */
    private function renderRepository(): string
    {
        return $this->service->renderRepository(
            'App\\Repository',
            'UserRepository',
            'App\\Entity\\User',
        );
    }

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
