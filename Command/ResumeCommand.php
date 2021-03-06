<?php

namespace Kaliop\eZMigrationBundle\Command;

use Kaliop\eZMigrationBundle\API\Value\Migration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
/**
 * Command to resume suspended migrations.
 *
 * @todo add support for resuming a set based on path
 * @todo add support for the separate-process cli switch
 */
class ResumeCommand extends AbstractCommand
{
    /**
     * Set up the command.
     *
     * Define the name, options and help text.
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('kaliop:migration:resume')
            ->setDescription('Restarts any suspended migrations.')
            ->addOption('ignore-failures', 'i', InputOption::VALUE_NONE, "Keep resuming migrations even if one fails")
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, "Do not ask any interactive question.")
            ->addOption('no-transactions', 'u', InputOption::VALUE_NONE, "Do not use a repository transaction to wrap each migration. Unsafe, but needed for legacy slot handlers")
            ->addOption('migration', 'm', InputOption::VALUE_REQUIRED, 'A single migration to resume (plain migration name).', null)
            ->setHelp(<<<EOT
The <info>kaliop:migration:resume</info> command allows you to resume any suspended migration
EOT
            );
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return null|int null or 0 if everything went fine, or an error code
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start = microtime(true);

        $this->getContainer()->get('ez_migration_bundle.step_executed_listener.tracing')->setOutput($output);

        $migrationService = $this->getMigrationService();

        $migrationName = $input->getOption('migration');
        if ($migrationName != null) {
            $suspendedMigration = $migrationService->getMigration($migrationName);
            if (!$suspendedMigration) {
                throw new \Exception("Migration '$migrationName' not found");
            }
            if ($suspendedMigration->status != Migration::STATUS_SUSPENDED) {
                throw new \Exception("Migration '$migrationName' is not suspended, can not resume it");
            }

            $suspendedMigrations = array($suspendedMigration);
        } else {
            $suspendedMigrations = $migrationService->getMigrationsByStatus(Migration::STATUS_SUSPENDED);
        };

        $output->writeln('<info>Found ' . count($suspendedMigrations) . ' suspended migrations</info>');

        if (!count($suspendedMigrations)) {
            $output->writeln('Nothing to do');
            return;
        }

        // ask user for confirmation to make changes
        if ($input->isInteractive() && !$input->getOption('no-interaction')) {
            $dialog = $this->getHelperSet()->get('question');
            if (!$dialog->ask(
                $input,
                $output,
                new ConfirmationQuestion('<question>Careful, the database will be modified. Do you want to continue Y/N ?</question>', false)
            )
            ) {
                $output->writeln('<error>Migration resuming cancelled!</error>');
                return 0;
            }
        }

        $executed = 0;
        $failed = 0;

        foreach($suspendedMigrations as $suspendedMigration) {
            $output->writeln("<info>Resuming {$suspendedMigration->name}</info>");

            try {
                $migrationService->resumeMigration($suspendedMigration, !$input->getOption('no-transactions'));

                $executed++;
            } catch (\Exception $e) {
                if ($input->getOption('ignore-failures')) {
                    $output->writeln("\n<error>Migration failed! Reason: " . $e->getMessage() . "</error>\n");
                    $failed++;
                    continue;
                }
                $output->writeln("\n<error>Migration aborted! Reason: " . $e->getMessage() . "</error>");
                return 1;
            }
        }

        $time = microtime(true) - $start;
        $output->writeln("Resumed $executed migrations, failed $failed");
        $output->writeln("Time taken: ".sprintf('%.2f', $time)." secs, memory: ".sprintf('%.2f', (memory_get_peak_usage(true) / 1000000)). ' MB');

        if ($failed) {
            return 2;
        }
    }
}
