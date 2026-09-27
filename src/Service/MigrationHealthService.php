<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Psr\Log\LoggerInterface;

/**
 * Reports the Doctrine migrations sync state.
 *
 * Why this exists (Risk #3 in RISK_MITIGATION.md):
 *   The single most expensive prod bug we have ever shipped was caused
 *   by a missing migration: 12 campus_* tables were created on the
 *   entities side but no migration ever wrote them to prod. Because
 *   sqlite tests use `doctrine:schema:create`, the bug never showed up
 *   locally — only when the prod MySQL hit `42S02 table not found`.
 *
 *   This service answers one question on every call:
 *     "Are ALL defined migrations applied to the connected database?"
 *
 *   If yes → return `in_sync=true` → endpoint returns 200 (healthy).
 *   If no  → return `in_sync=false` → endpoint returns 500 (broken).
 *
 *   UptimeRobot (or Better Stack) hitting /api/health/migrations every
 *   60s will alert us the moment a new migration is shipped but not
 *   yet applied to prod.
 *
 * Cost: a single read-only SQL query against the
 * `doctrine_migration_versions` table + a filesystem scan of the
 * `migrations/` directory. Both are O(n_migrations), negligible.
 *
 * Resilience note:
 *   Doctrine Migrations v3's TableMetadataStorage is sensitive to schema
 *   differences between what's in MySQL vs what Doctrine "expects". On
 *   prod MySQL the introspection often reports tiny platform-option
 *   differences (charset/collation hints) that flag the table as
 *   "not up to date" — even when it functionally IS up to date. When
 *   that happens we fall back to a raw SQL query, which is the same
 *   query Doctrine itself uses under the hood.
 */
final class MigrationHealthService
{
    /**
     * Single-instance cache: avoid hitting the filesystem and DB on
     * every monitoring probe. 10s TTL is enough because:
     *   - UptimeRobot typically polls at 60s cadence.
     *   - Deploys take at least 30s end-to-end, so 10s lag is invisible.
     */
    private const CACHE_TTL_SECONDS = 10;

    /** @var array<string, mixed>|null */
    private ?array $cached = null;
    private int $cachedAt = 0;

    public function __construct(
        private readonly DependencyFactory $dependencyFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Compute (or return cached) migrations sync state.
     *
     * @return array{
     *     in_sync: bool,
     *     available: int,
     *     executed: int,
     *     pending: int,
     *     latest_available: ?string,
     *     latest_executed: ?string,
     *     pending_versions: string[],
     *     unavailable_versions: string[],
     *     checked_at: string,
     *     error: ?string,
     * }
     */
    public function check(): array
    {
        if ($this->cached !== null && (time() - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
            return $this->cached;
        }

        $checkedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        // "available" comes from the filesystem + Doctrine's class scanner.
        // This is a pure-PHP scan — no DB involved, so it almost never fails.
        try {
            $availableList   = $this->dependencyFactory->getMigrationPlanCalculator()->getMigrations();
            $availableCount  = $availableList->count();
            $latestAvailable = $availableCount > 0
                ? (string) $availableList->getLast()->getVersion()
                : null;
        } catch (\Throwable $e) {
            // Filesystem scanner failed — this is unusual (read perm denied?).
            // Bubble up as a hard error so we don't silently report "in sync".
            return $this->fail($checkedAt, sprintf('available scanner: %s: %s', $e::class, $e->getMessage()));
        }

        // "executed" comes from the DB. We try Doctrine's API first; if
        // it raises MetadataStorageError(notUpToDate) — a known quirk on
        // MySQL where platform-option introspection differs from what
        // Doctrine "expects" — we fall back to a raw SQL query against
        // doctrine_migration_versions. We also remember which path we
        // took so the diff calculation can use a consistent source.
        [$executedList, $usedRawFallback] = $this->fetchExecutedList();
        if ($executedList === null) {
            return $this->fail($checkedAt, 'metadata storage unavailable; ran doctrine:migrations:sync-metadata-storage?');
        }
        $executedCount = $executedList->count();
        $latestExecuted = $executedCount > 0
            ? (string) $executedList->getLast()->getVersion()
            : null;

        // "pending" + "unavailable" — these need both lists. When we used
        // the raw SQL fallback, Doctrine's status calc would re-query
        // the broken storage. So we use the raw version-string diff
        // in that case. Otherwise we use Doctrine's API which is
        // already correct.
        if ($usedRawFallback) {
            $availableVersions   = $this->extractVersions($availableList);
            $executedVersions    = $this->extractExecutedVersions($executedList);
            $pendingVersions     = array_values(array_diff($availableVersions, $executedVersions));
            $unavailableVersions = array_values(array_diff($executedVersions, $availableVersions));
            $pendingCount        = count($pendingVersions);
            $unavailableCount    = count($unavailableVersions);
        } else {
            try {
                $statusCalc   = $this->dependencyFactory->getMigrationStatusCalculator();
                $newMigrations    = $statusCalc->getNewMigrations();
                $unavailable      = $statusCalc->getExecutedUnavailableMigrations();
                $pendingVersions  = $this->extractVersions($newMigrations);
                $unavailableVersions = $this->extractExecutedVersions($unavailable);
                $pendingCount     = $newMigrations->count();
                $unavailableCount = $unavailable->count();
            } catch (\Throwable $e) {
                $this->logger->warning('MigrationHealth: status calculator unavailable, using raw diff', [
                    'reason' => $e->getMessage(),
                ]);
                $availableVersions   = $this->extractVersions($availableList);
                $executedVersions    = $this->extractExecutedVersions($executedList);
                $pendingVersions     = array_values(array_diff($availableVersions, $executedVersions));
                $unavailableVersions = array_values(array_diff($executedVersions, $availableVersions));
                $pendingCount        = count($pendingVersions);
                $unavailableCount    = count($unavailableVersions);
            }
        }

        $inSync = $pendingCount === 0 && $unavailableCount === 0;

        $payload = [
            'in_sync'              => $inSync,
            'available'            => $availableCount,
            'executed'             => $executedCount,
            'pending'              => $pendingCount,
            'latest_available'     => $latestAvailable,
            'latest_executed'      => $latestExecuted,
            'pending_versions'     => $pendingVersions,
            'unavailable_versions' => $unavailableVersions,
            'checked_at'           => $checkedAt,
            'error'                => null,
        ];

        if (! $inSync) {
            $this->logger->critical('MigrationHealth: prod DB is out of sync with codebase', [
                'available'           => $availableCount,
                'executed'            => $executedCount,
                'pending'             => $pendingCount,
                'pending_versions'    => $pendingVersions,
                'unavailable_in_db'   => $unavailableCount,
                'latest_available'    => $latestAvailable,
                'latest_executed'     => $latestExecuted,
                'checked_at'          => $checkedAt,
            ]);
        }

        $this->cached   = $payload;
        $this->cachedAt = time();

        return $payload;
    }

    /**
     * Forget the cache. Used by the admin force-check endpoint.
     */
    public function resetCache(): void
    {
        $this->cached   = null;
        $this->cachedAt = 0;
    }

    /**
     * Try the Doctrine API; on metadata-storage drift, fall back to raw
     * SQL. Returns [list, usedRawFallback]:
     *   - list: ExecutedMigrationsList (or null on total failure)
     *   - usedRawFallback: true if the raw SQL path was used (callers
     *     should rely on raw diffs, not the Doctrine status calc)
     */
    private function fetchExecutedList(): array
    {
        try {
            return [$this->dependencyFactory->getMetadataStorage()->getExecutedMigrations(), false];
        } catch (\Doctrine\Migrations\Exception\MetadataStorageError $e) {
            $this->logger->warning('MigrationHealth: metadata storage not up to date, falling back to raw query', [
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MigrationHealth: metadata storage read failed', [
                'reason' => $e->getMessage(),
            ]);
            return [null, false];
        }

        // Fallback: raw SQL against doctrine_migration_versions.
        // This is the exact same query Doctrine itself runs internally
        // when the storage is initialised. We get the Connection from
        // the DependencyFactory (not as a separate constructor arg) so
        // tests can swap the DependencyFactory in one shot.
        try {
            $rows = $this->dependencyFactory->getConnection()
                ->fetchAllAssociative('SELECT version, executed_at FROM doctrine_migration_versions ORDER BY version');
        } catch (\Throwable $e) {
            $this->logger->error('MigrationHealth: raw metadata query failed', [
                'reason' => $e->getMessage(),
            ]);
            return [null, false];
        }

        $items = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $version = new \Doctrine\Migrations\Version\Version($row['version']);
            $executedAt = !empty($row['executed_at'])
                ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['executed_at'])
                : null;
            $items[(string) $version] = new \Doctrine\Migrations\Metadata\ExecutedMigration(
                $version,
                $executedAt,
            );
        }
        return [new ExecutedMigrationsList($items), true];
    }

    /**
     * @return array{
     *     in_sync: false,
     *     available: 0,
     *     executed: 0,
     *     pending: 0,
     *     latest_available: null,
     *     latest_executed: null,
     *     pending_versions: string[],
     *     unavailable_versions: string[],
     *     checked_at: string,
     *     error: string,
     * }
     */
    private function fail(string $checkedAt, string $error): array
    {
        $payload = [
            'in_sync'              => false,
            'available'            => 0,
            'executed'             => 0,
            'pending'              => 0,
            'latest_available'     => null,
            'latest_executed'      => null,
            'pending_versions'     => [],
            'unavailable_versions' => [],
            'checked_at'           => $checkedAt,
            'error'                => $error,
        ];
        $this->cached   = $payload;
        $this->cachedAt = time();
        return $payload;
    }

    /**
     * @param AvailableMigrationsList $list
     * @return string[]
     */
    private function extractVersions(AvailableMigrationsList $list): array
    {
        $out = [];
        foreach ($list->getItems() as $m) {
            $out[] = (string) $m->getVersion();
        }
        return $out;
    }

    /**
     * @return string[]
     */
    private function extractExecutedVersions(ExecutedMigrationsList $list): array
    {
        $out = [];
        foreach ($list->getItems() as $m) {
            $out[] = (string) $m->getVersion();
        }
        return $out;
    }
}