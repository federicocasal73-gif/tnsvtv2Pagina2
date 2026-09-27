<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
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

        try {
            $statusCalculator = $this->dependencyFactory->getMigrationStatusCalculator();

            $newMigrations = $statusCalculator->getNewMigrations();
            $unavailable   = $statusCalculator->getExecutedUnavailableMigrations();

            $availableList  = $this->dependencyFactory->getMigrationPlanCalculator()->getMigrations();
            $executedList   = $this->dependencyFactory->getMetadataStorage()->getExecutedMigrations();

            $availableCount = $availableList->count();
            $executedCount  = $executedList->count();

            $pendingVersions     = $this->extractVersions($newMigrations);
            $unavailableVersions = $this->extractExecutedVersions($unavailable);

            $latestAvailable = $availableCount > 0
                ? (string) $availableList->getLast()->getVersion()
                : null;
            $latestExecuted  = $executedCount > 0
                ? (string) $executedList->getLast()->getVersion()
                : null;

            $pendingCount = $newMigrations->count();
            $inSync       = $pendingCount === 0 && $unavailable->count() === 0;

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
                    'unavailable_in_db'   => $unavailable->count(),
                    'latest_available'    => $latestAvailable,
                    'latest_executed'     => $latestExecuted,
                    'checked_at'          => $checkedAt,
                ]);
            }

            $this->cached   = $payload;
            $this->cachedAt = time();

            return $payload;
        } catch (\Throwable $e) {
            // Never let a broken migrations check take the canary down.
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
                'error'                => sprintf('%s: %s', $e::class, $e->getMessage()),
            ];

            $this->cached   = $payload;
            $this->cachedAt = time();

            return $payload;
        }
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
    private function extractExecutedVersions(\Doctrine\Migrations\Metadata\ExecutedMigrationsList $list): array
    {
        $out = [];
        foreach ($list->getItems() as $m) {
            $out[] = (string) $m->getVersion();
        }

        return $out;
    }
}