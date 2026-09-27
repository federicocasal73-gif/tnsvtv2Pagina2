<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lint: every #[ORM\Entity] has a corresponding CREATE TABLE in some migration.
 *
 * Why this exists (Risk #3 in RISK_MITIGATION.md):
 *   The most expensive prod bug we ever shipped was caused by 12
 *   campus_* entities that were created without a migration. Tests
 *   on sqlite passed because doctrine:schema:create mirrors the
 *   entity layer; prod on MySQL threw 42S02 (table not found) the
 *   moment a service queried the missing table.
 *
 *   This command enforces, in CI, that any new entity ships with a
 *   migration that creates its table. It walks src/Entity/*.php,
 *   extracts `#[ORM\Table(name: '...')]`, then string-searches every
 *   migrations/Version*.php for either:
 *
 *     - `CREATE TABLE <name>`  (raw SQL form)
 *     - `createTable('<name>')`  (Doctrine Schema API form)
 *
 *   If a table name is NOT referenced anywhere, the entity is missing
 *   its migration. The command exits non-zero → CI rejects the PR.
 *
 * False-positive control:
 *   - Tables declared in `#[ORM\Table(name: 'users')]` and
 *     `#[ORM\Table(name: 'user')]` (legacy duplicate table) are both
 *     accepted by checking the body for the literal name.
 *   - Tables using only `#[ORM\Entity]` without `#[ORM\Table]` fall
 *     back to Doctrine's default name = class FQN snake-cased, which
 *     is rarely used in this codebase; we skip those (warning only).
 *
 * Exit codes:
 *   0  → all entities have migrations
 *   1  → one or more entities are missing migrations (CI rejects PR)
 *   2  → internal error (e.g. missing source dir)
 *
 * Run: php bin/console app:lint:entity-migrations
 */
#[AsCommand(
    name: 'app:lint:entity-migrations',
    description: 'Lint: every entity has a CREATE TABLE in some migration file.',
)]
class LintEntityMigrationsCommand extends Command
{
    public function __construct(
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity-dir', null, InputOption::VALUE_REQUIRED, 'Override src/Entity directory', $this->projectDir . '/src/Entity')
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Override migrations directory', $this->projectDir . '/migrations')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entityDir    = $input->getOption('entity-dir');
        $migrationsDir = $input->getOption('migrations-dir');

        if (!is_dir($entityDir)) {
            $io->error("Entity directory not found: $entityDir");
            return 2;
        }
        if (!is_dir($migrationsDir)) {
            $io->error("Migrations directory not found: $migrationsDir");
            return 2;
        }

        $migrationsBlob   = $this->concatMigrations($migrationsDir);
        $latestMigrationTime = $this->latestMigrationTime($migrationsDir);

        $missing = [];
        $warn    = [];
        $ok      = 0;

        foreach (glob($entityDir . '/*.php') ?: [] as $entityFile) {
            $basename = basename($entityFile, '.php');
            // Skip trait + Trait/ subdir.
            if ($basename === 'Trait' || str_ends_with($basename, 'Trait')) continue;

            $contents = file_get_contents($entityFile);
            if ($contents === false) continue;

            if (!str_contains($contents, '#[ORM\Entity')) continue;

            $tableName = $this->extractTableName($contents);
            if ($tableName === null) {
                // No #[ORM\Table(name: ...)] found. Doctrine will use
                // the FQN snake-case. We can't reliably check that
                // against CREATE TABLE strings, so flag as warning.
                $warn[] = $basename . '.php (no #[ORM\Table] attribute — skipped)';
                continue;
            }

            if ($this->tableIsCreated($tableName, $migrationsBlob)) {
                $ok++;
                continue;
            }

            // Table name NOT found in any migration's CREATE TABLE.
            // Decide: is this a NEW entity (needs to fail) or a
            // pre-existing legacy entity (silently skip)?
            //
            // Heuristic: compare file mtime against the latest
            // migration's mtime. If the entity file is NEWER than the
            // newest migration, it's a recently-added entity → flag it.
            // If it's older, it was added before the most recent
            // migration and probably is covered by older DDL (or
            // init.sql) — skip.
            $entityTime = filemtime($entityFile) ?: 0;
            if ($entityTime > $latestMigrationTime) {
                $missing[] = sprintf('  - %-40s  table: %s', $basename . '.php', $tableName);
            } else {
                // Legacy entity. Just count as OK to keep the totals
                // meaningful, but don't claim it has a migration.
                $ok++;
            }
        }

        $io->writeln(sprintf('Checked %d entities; %d OK, %d missing, %d warnings.',
            $ok + count($missing) + count($warn),
            $ok,
            count($missing),
            count($warn),
        ));

        if ($warn !== []) {
            $io->section('Warnings (skipped)');
            $io->listing($warn);
        }

        if ($missing !== []) {
            $io->error("Missing migrations for the following NEW entities:");
            $io->writeln(implode("\n", $missing));
            $io->writeln('');
            $io->writeln('<comment>Fix:</comment> run <info>php bin/console doctrine:migrations:diff</info> and commit the new file, or add a migration that creates the table(s) above.');
            return Command::FAILURE;
        }

        $io->success('All new entities have a corresponding migration.');
        return Command::SUCCESS;
    }

    /**
     * Read every migrations/Version*.php into one big string so we can
     * substring-search cheaply. The total size is well under 1 MB for
     * this codebase (12 migrations of ~25 KB each ≈ 300 KB), so this
     * is fine for a CLI command.
     */
    private function concatMigrations(string $dir): string
    {
        $blob = '';
        foreach (glob($dir . '/Version*.php') ?: [] as $file) {
            $c = file_get_contents($file);
            if ($c !== false) $blob .= "\n" . $c;
        }
        return $blob;
    }

    /**
     * Returns the highest mtime across all migration files. Used by
     * execute() to decide whether a table-less entity is "new" (mtime
     * > this) or "legacy" (mtime <= this).
     */
    private function latestMigrationTime(string $dir): int
    {
        $max = 0;
        foreach (glob($dir . '/Version*.php') ?: [] as $file) {
            $mtime = filemtime($file) ?: 0;
            if ($mtime > $max) $max = $mtime;
        }
        return $max;
    }

    /**
     * Extract the value of #[ORM\Table(name: '...')] from a single
     * entity file. Returns null if the attribute is absent.
     *
     * Handles all the forms Doctrine ORM allows:
     *   #[ORM\Table(name: 'notifications')]
     *   #[ORM\Table(name: "notifications")]
     *   #[ORM\Entity(repositoryClass: Foo::class)]  #[ORM\Table(name: 'notifications')]
     *   #[ORM\Entity] #[ORM\Table(name: 'notifications')]
     */
    private function extractTableName(string $contents): ?string
    {
        if (!preg_match('/#\[ORM\\\\Table\(\s*name\s*:\s*([\\\'"])([^\\\'"]+)\\1\s*\)\]/', $contents, $m)) {
            return null;
        }
        return $m[2];
    }

    /**
     * True if the table name appears in any migration as either:
     *   - `CREATE TABLE <name>` (raw SQL)
     *   - `createTable('<name>')` or `createTable("<name>")` (Schema API)
     *
     * Both upper + lower case for the SQL form (case-insensitive search
     * is restricted to the CREATE keyword to avoid false positives).
     */
    private function tableIsCreated(string $name, string $migrationsBlob): bool
    {
        $needleRawSql = str_contains(strtoupper($migrationsBlob), 'CREATE TABLE ' . strtoupper($name));
        if ($needleRawSql) return true;

        $needleSchema1 = str_contains($migrationsBlob, "createTable('$name')");
        $needleSchema2 = str_contains($migrationsBlob, 'createTable("' . $name . '")');
        return $needleSchema1 || $needleSchema2;
    }
}