<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Tests\ValueObject;

use Matatirosoln\DoctrineOdataBundle\ValueObject\EntityGenerationPlan;
use PHPUnit\Framework\TestCase;

class EntityGenerationPlanTest extends TestCase
{
    private EntityGenerationPlan $plan;

    protected function setUp(): void
    {
        $this->plan = new EntityGenerationPlan(
            entitySet:       'User',
            entityClass:     'App\\Entity\\User',
            entityFile:      '/var/www/project/src/Entity/User.php',
            entityCode:      '<?php // entity',
            repositoryClass: 'App\\Repository\\UserRepository',
            repositoryFile:  '/var/www/project/src/Repository/UserRepository.php',
            repositoryCode:  '<?php // repository',
            propertyCount:   3,
            primaryKeyField: '__pk_UserID',
            projectDir:      '/var/www/project',
        );
    }

    public function testRelativePathStripsProjectRoot(): void
    {
        $this->assertSame(
            'src/Entity/User.php',
            $this->plan->relativePath('/var/www/project/src/Entity/User.php'),
        );
    }

    public function testRelativePathStripsProjectRootForRepository(): void
    {
        $this->assertSame(
            'src/Repository/UserRepository.php',
            $this->plan->relativePath('/var/www/project/src/Repository/UserRepository.php'),
        );
    }

    public function testRelativePathReturnsAbsolutePathWhenOutsideProject(): void
    {
        $this->assertSame(
            '/tmp/some/other/file.php',
            $this->plan->relativePath('/tmp/some/other/file.php'),
        );
    }

    public function testPropertiesAreReadable(): void
    {
        $this->assertSame('User',               $this->plan->entitySet);
        $this->assertSame('App\\Entity\\User',  $this->plan->entityClass);
        $this->assertSame(3,                    $this->plan->propertyCount);
        $this->assertSame('__pk_UserID',        $this->plan->primaryKeyField);
        $this->assertSame('<?php // entity',    $this->plan->entityCode);
        $this->assertSame('<?php // repository', $this->plan->repositoryCode);
    }
}
