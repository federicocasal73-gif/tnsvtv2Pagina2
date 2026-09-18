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
 * Removes stale (no-longer-referenced) compiled assets from public/assets/.
 *
 * Why this exists:
 *   asset-map:compile generates new hashed filenames on every deploy and
 *   appends them to public/assets/manifest.json. The OLD hashed files stay
 *   on disk forever. After ~10 deploys you accumulate hundreds of orphan
 *   controllers-*.js / styles/*-*.css / js/modules/*-*.js files that waste
 *   disk and confuse humans reading `ls public/assets/controllers/`.
 *
 * Algorithm:
 *   1. Parse public/assets/manifest.json → set of "live" relative paths.
 *   2. Walk public/assets/{controllers,styles,styles/components,js,js/modules,
 *      third_party,@symfony}/ recursively.
 *   3. Anything on disk NOT in the live set → delete.
 *
 * IMPORTANT — deploy ordering:
 *   This command MUST be run AFTER asset-map:compile, not before. The
 *   manifest is only valid once fresh assets are on disk. If you run clean
 *   before compile, you may delete files that the manifest still points to,
 *   and asset-map:compile will then re-create them anyway (so it's safe
 *   in practice but wasteful). The recommended flow is:
 *
 *     php bin/console asset-map:compile --env=prod
 *     php bin/console app:assets:clean        --env=prod --apply
 *
 *   That way clean only sees files NOT in the just-updated manifest, which
 *   is exactly the set of stale assets.
 *
 * Safe by default: dry-run prints what WOULD be deleted. Pass --apply to
 * actually delete.
 *
 * Run: php bin/console app:assets:clean [--apply] [--show-kept]
 */
#[AsCommand(
    name: 'app:assets:clean',
    description: 'Delete stale compiled assets not referenced by manifest.json',
)]
class AssetsCleanCommand extends Command
{
    /** Files in public/assets/ root we NEVER touch (entrypoint manifests). */
    private const ROOT_KEEP = [
        'manifest.json',
        'importmap.json',
        '.htaccess',
        'index.html',
    ];

    /** Subdirectories of public/assets/ that hold generated files. */
    private const SCAN_DIRS = [
        'controllers',
        'styles',
        'styles/components',
        'js',
        'js/modules',
        'third_party',
        '@symfony',
    ];

    /** Filename patterns within root or SCAN_DIRS we always keep. */
    private const KEEP_PATTERNS = [
        '#^stimulus_bootstrap-[\w-]+\.js$#',
        '#^loader-[\w-]+\.js$#',
        '#^entrypoint\.[\w-]+\.json$#',
        '#^controllers-[\w-]+\.js$#',  // Stimulus bundle controllers manifest
        '#^app-[\w-]+\.js$#',          // app.js entrypoint
    ];

    public function __construct(
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete files (default is dry-run)')
            ->addOption('show-kept', null, InputOption::VALUE_NONE, 'Show every kept file (noisy)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $verbose = (bool) $input->getOption('show-kept');

        $manifestPath = $this->projectDir . '/public/assets/manifest.json';
        if (!is_file($manifestPath)) {
            $io->error("manifest.json not found at $manifestPath. Run asset-map:compile first.");
            return Command::FAILURE;
        }

        $manifestRaw = file_get_contents($manifestPath);
        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            $io->error('manifest.json is not a JSON object.');
            return Command::FAILURE;
        }

        // Build set of "live" relative paths. Values in the manifest look
        // like "/assets/controllers/foo-abc123.js" — we normalise to
        // "controllers/foo-abc123.js" for comparison with disk paths.
        $live = [];
        foreach ($manifest as $hashed) {
            if (!is_string($hashed) || !str_starts_with($hashed, '/assets/')) continue;
            $live[substr($hashed, strlen('/assets/'))] = true;
        }
        // Also keep files in the root of public/assets/ that aren't in
        // the manifest (importmap.json, .htaccess, etc.).
        $live['importmap.json'] = true;

        $assetsRoot = $this->projectDir . '/public/assets';
        $deleted = [];
        $kept = 0;

        // 1. Scan each generated subdirectory
        foreach (self::SCAN_DIRS as $sub) {
            $dir = $assetsRoot . '/' . $sub;
            if (!is_dir($dir)) continue;
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                /** @var \SplFileInfo $file */
                $path = $file->getPathname();
                $rel = substr($path, strlen($assetsRoot) + 1); // "controllers/foo.js"
                $rel = str_replace('\\', '/', $rel);

                if (isset($live[$rel])) { $kept++; continue; }
                if ($this->matchesKeepPattern($file->getFilename())) { $kept++; continue; }

                if ($apply) {
                    @unlink($path);
                }
                $deleted[] = $rel;
            }
        }

        // 2. Sweep the root of public/assets/ for stale top-level files.
        //    Skip directories (those are SCAN_DIRS handled above) and the
        //    root-keep list.
        $iter = new \DirectoryIterator($assetsRoot);
        foreach ($iter as $file) {
            if ($file->isDir()) continue;
            $name = $file->getFilename();
            if (in_array($name, self::ROOT_KEEP, true)) continue;
            if ($this->matchesKeepPattern($name)) { $kept++; continue; }

            $rel = 'entrypoints/' . $name; // synthetic key for dedup
            // For root files, "live" check is: is this exact name kept by manifest?
            $isLive = isset($manifest[$name]) || isset($live[$name]);
            if ($isLive) { $kept++; continue; }

            if ($apply) {
                @unlink($file->getPathname());
            }
            $deleted[] = $name;
        }

        // 3. Remove empty directories left behind by deletions (cosmetic).
        if ($apply) {
            foreach (self::SCAN_DIRS as $sub) {
                $this->removeEmptyDirs($assetsRoot . '/' . $sub);
            }
        }

        // Report
        if ($apply) {
            $io->success(sprintf(
                'Cleaned %d stale asset file(s). Kept %d referenced file(s).',
                count($deleted),
                $kept
            ));
        } else {
            $io->note(sprintf(
                "DRY-RUN: would delete %d stale asset file(s); would keep %d.\nRe-run with --apply to actually delete.",
                count($deleted),
                $kept
            ));
        }

        if ($verbose && count($deleted) > 0 && count($deleted) <= 80) {
            $io->section('Files that would be / were deleted');
            $io->listing($deleted);
        } elseif (count($deleted) > 80) {
            $io->writeln(sprintf('  (first 80:)'));
            $io->listing(array_slice($deleted, 0, 80));
        }

        return Command::SUCCESS;
    }

    private function matchesKeepPattern(string $filename): bool
    {
        foreach (self::KEEP_PATTERNS as $pattern) {
            if (preg_match($pattern, $filename)) return true;
        }
        return false;
    }

    private function removeEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        $remaining = array_diff($items, ['.', '..']);
        if (count($remaining) === 0) {
            @rmdir($dir);
            return;
        }
        foreach ($remaining as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeEmptyDirs($path);
                // After recursion, try again to remove if now empty
                if (count(array_diff((array) @scandir($path), ['.', '..'])) === 0) {
                    @rmdir($path);
                }
            }
        }
    }
}
