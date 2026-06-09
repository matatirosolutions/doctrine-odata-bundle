<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataBundle\Command;

use InvalidArgumentException;
use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\GeneratorService;
use Matatirosoln\DoctrineOdataBundle\Service\EntityGenerator\PlanningService;
use Matatirosoln\DoctrineOdataBundle\ValueObject\EntityGenerationPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI entry point for entity generation.
 *
 * This command is responsible only for user interaction — parsing arguments,
 * displaying the generation plan, asking for confirmation, and reporting results.
 * All logic is delegated to PlanningService (metadata inspection) and
 * GeneratorService (code rendering and file writing).
 *
 * Usage:
 *
 *   bin/console doctrine:odata:entity:generate <entity-set> <class-name>
 *
 * Examples:
 *
 *   bin/console doctrine:odata:entity:generate User User
 *   bin/console doctrine:odata:entity:generate Users "Users\User"
 */
#[AsCommand(
    name: 'doctrine:odata:entity:generate',
    description: 'Generate a Doctrine entity and repository from an OData $metadata entity set',
)]
class GenerateEntityCommand extends Command
{
    public function __construct(
        private readonly PlanningService  $planner,
        private readonly GeneratorService $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'entity-set',
                InputArgument::REQUIRED,
                'The OData entity set name (the table name as it appears in $metadata)',
            )
            ->addArgument(
                'class-name',
                InputArgument::REQUIRED,
                'PHP class name, optionally with sub-namespace using backslashes (e.g. User or "Users\\User")',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entitySet = $input->getArgument('entity-set');
        $className = $input->getArgument('class-name');

        try {
            $plan = $this->planner->plan($entitySet, $className);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->title(sprintf('Generating entity for OData entity set "%s"', $plan->entitySet));
        $io->table(
            ['', ''],
            [
                ['Entity class',     $plan->entityClass],
                ['Entity file',      $plan->relativePath($plan->entityFile)],
                ['Repository class', $plan->repositoryClass],
                ['Repository file',  $plan->relativePath($plan->repositoryFile)],
                ['Properties found', (string) $plan->propertyCount],
                ['Primary key',      $plan->primaryKeyField],
            ],
        );

        if (!$io->confirm('Generate these files?')) {
            $io->warning('Aborted.');
            return Command::SUCCESS;
        }

        $this->writeWithConfirm($io, $plan->entityFile, $plan->entityCode, $plan);
        $this->writeWithConfirm($io, $plan->repositoryFile, $plan->repositoryCode, $plan);

        $io->success('Done. Review the generated files and adjust property visibility or hooks as needed.');
        return Command::SUCCESS;
    }

    /**
     * Writes a file, asking the user for confirmation if it already exists.
     */
    private function writeWithConfirm(
        SymfonyStyle $io,
        string $path,
        string $content,
        EntityGenerationPlan $plan,
    ): void {
        if (file_exists($path)) {
            if (!$io->confirm(
                sprintf('  %s already exists. Overwrite?', $plan->relativePath($path)),
                false,
            )) {
                $io->note(sprintf('Skipped: %s', $plan->relativePath($path)));
                return;
            }
        }

        $this->generator->writeFile($path, $content);
        $io->writeln(sprintf('  <info>Created</info> %s', $plan->relativePath($path)));
    }
}
