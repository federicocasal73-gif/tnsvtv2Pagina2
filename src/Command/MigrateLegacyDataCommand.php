<?php

namespace App\Command;

use App\Service\Migration\LegacyDataMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-legacy',
    description: 'Migrate data from legacy tnsvt.com DB to v2 lightskyblue-turtle-221397.hostingersite.com DB',
)]
class MigrateLegacyDataCommand extends Command
{
    public function __construct(
        private LegacyDataMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without writing (validates queries)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually migrate (writes to destination)')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write report JSON to this path', 'var/migration-report.json')
            ->addOption('legacy-url', null, InputOption::VALUE_REQUIRED, 'Override LEGACY_DATABASE_URL env var')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $isDryRun = !$input->getOption('execute') || $input->getOption('dry-run');
        $mode = $isDryRun ? 'DRY-RUN' : 'EXECUTE';

        // Apply legacy URL override
        if ($url = $input->getOption('legacy-url')) {
            $this->migrator->setLegacyUrl($url);
        }

        $io->title('TNSVT Reino v2 — Legacy Data Migration');
        $io->writeln(sprintf('Mode: <info>%s</info>', $mode));
        $io->newLine();

        if (!$isDryRun) {
            // Auto-accept when --execute is used (no TTY available in non-interactive contexts)
            if ($input->isInteractive() && !$io->confirm('This will WRITE data to the v2 database. Continue?', false)) {
                $io->warning('Cancelled by user.');
                return Command::FAILURE;
            }
        }

        try {
            $report = $isDryRun ? $this->migrator->dryRun() : $this->migrator->execute(dryRun: false);

            // Print table status
            $io->section('Table migration status');
            $rows = [];
            foreach ($report['tables'] as $table => $info) {
                $statusColor = match ($info['status']) {
                    'migrated', 'would-migrate' => 'green',
                    'empty-source' => 'gray',
                    'skipped' => 'yellow',
                    default => 'red',
                };
                $rows[] = [
                    $table,
                    "<fg={$statusColor}>{$info['status']}</>",
                    $info['source_count'] ?? '-',
                    $info['dest_count'] ?? '-',
                    $info['rows_migrated'] ?? '-',
                ];
            }
            $io->table(['Table', 'Status', 'Source', 'Dest', 'Migrated'], $rows);

            $io->newLine();
            $io->writeln(sprintf('Total rows migrated: <info>%d</info>', $report['total_rows'] ?? 0));

            // Write report to file
            $reportPath = $input->getOption('report');
            if (!str_starts_with($reportPath, '/')) {
                $reportPath = dirname(__DIR__, 2) . '/' . $reportPath;
            }
            @mkdir(dirname($reportPath), 0755, true);
            $this->migrator->writeReport($report, $reportPath);
            $io->success("Report written to $reportPath");

            if ($isDryRun) {
                $io->note('This was a DRY-RUN. No data was written. Re-run with --execute to perform migration.');
            } else {
                $io->success('Migration complete!');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}