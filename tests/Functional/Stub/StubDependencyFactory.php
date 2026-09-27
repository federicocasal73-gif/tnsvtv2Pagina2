<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stub;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Version;

/**
 * Test stub for Doctrine\Migrations\DependencyFactory.
 *
 * The real DependencyFactory has a private constructor (it is meant to
 * be instantiated only via the bundle's factory methods). For unit
 * tests, we instantiate this subclass via reflection
 * (ReflectionClass::newInstanceWithoutConstructor) — bypassing the
 * real wiring entirely — and stub only the three methods the
 * MigrationHealthService uses:
 *
 *   - getMigrationStatusCalculator()
 *   - getMigrationPlanCalculator()
 *   - getMetadataStorage()
 *
 * Configured via setConfig(), setConfigAndCounter() or setThrowable().
 */
final class StubDependencyFactory extends DependencyFactory
{
    /** @var array<string, mixed> */
    private array $config = [];
    /** @var int|null */
    private ?int $counter = null;
    private ?\Throwable $throwable = null;
    /** When true, only getMetadataStorage() throws; everything else works. */
    private bool $throwOnlyOnStorage = false;

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): void
    {
        $this->config    = $config;
        $this->throwable = null;
        $this->counter   = null;
        $this->throwOnlyOnStorage = false;
    }

    /**
     * @param array<string, mixed> $config
     * @param-out int $counter
     */
    public function setConfigAndCounter(array $config, int &$counter): void
    {
        $this->config    = $config;
        $this->throwable = null;
        $this->counter   = &$counter;
        $this->throwOnlyOnStorage = false;
    }

    public function setThrowable(\Throwable $e): void
    {
        $this->config    = [];
        $this->throwable = $e;
        $this->counter   = null;
        $this->throwOnlyOnStorage = false;
    }

    /**
     * Like setThrowable() but only the metadata storage call fails —
     * everything else (status calculator, plan calculator) succeeds.
     * This simulates the real-world case where MySQL introspection
     * disagrees with Doctrine's expected schema.
     */
    public function setStorageThrows(\Throwable $e, array $config): void
    {
        $this->config = $config;
        $this->throwable = $e;
        $this->counter = null;
        $this->throwOnlyOnStorage = true;
    }

    public static function fromConfig(array $config): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->config = $config;

        return $instance;
    }

    private function maybeThrow(bool $force = false): void
    {
        // `force=true` is used by getMetadataStorage() to throw even
        // in "throwOnlyOnStorage" mode — that's the entire purpose of
        // that mode.
        if ($this->throwable !== null && ($force || !$this->throwOnlyOnStorage)) {
            throw $this->throwable;
        }
        if ($this->counter !== null && !$this->throwOnlyOnStorage) {
            $this->counter++;
        }
    }

    public function getMigrationStatusCalculator(): \Doctrine\Migrations\Version\MigrationStatusCalculator
    {
        // Only throw if this is the "throw on everything" mode.
        if (!$this->throwOnlyOnStorage) {
            $this->maybeThrow();
        }
        $cfg = $this->config;

        return new class($cfg) implements \Doctrine\Migrations\Version\MigrationStatusCalculator {
            public function __construct(private array $c) {}

            public function getExecutedUnavailableMigrations(): ExecutedMigrationsList
            {
                $items = [];
                foreach ($this->c['unavailable'] as $v) {
                    $items[] = new ExecutedMigration(new Version($v));
                }
                return new ExecutedMigrationsList($items);
            }

            public function getNewMigrations(): AvailableMigrationsList
            {
                $items = [];
                foreach ($this->c['pending'] as $v) {
                    $items[] = new AvailableMigration(new Version($v), StubDependencyFactory::stubMigration());
                }
                return new AvailableMigrationsList($items);
            }
        };
    }

    public function getMigrationPlanCalculator(): \Doctrine\Migrations\Version\MigrationPlanCalculator
    {
        // Only throw if this is the "throw on everything" mode.
        if (!$this->throwOnlyOnStorage) {
            $this->maybeThrow();
        }
        $cfg = $this->config;

        return new class($cfg) implements \Doctrine\Migrations\Version\MigrationPlanCalculator {
            public function __construct(private array $c) {}

            public function getMigrations(): AvailableMigrationsList
            {
                $items = [];

                // If `availableVersions` is set, use those exact strings.
                // This is the path the raw-SQL fallback tests take so
                // the stub's "available" matches the raw rows.
                if (!empty($this->c['availableVersions'])) {
                    foreach ($this->c['availableVersions'] as $ver) {
                        $items[] = new AvailableMigration(new Version($ver), StubDependencyFactory::stubMigration());
                    }
                    return new AvailableMigrationsList($items);
                }

                // Otherwise build a synthetic list from `available` count
                // + `latestAvailable`.
                $latest = $this->c['latestAvailable'] ?? 'Version20260924000000';
                $base = substr($latest, 0, -4);
                $rev  = (int) substr($latest, -4);
                for ($i = 0; $i < $this->c['available']; $i++) {
                    $seq = $rev - ($this->c['available'] - 1 - $i);
                    $ver = sprintf('%s%04d', $base, $seq);
                    $items[] = new AvailableMigration(new Version($ver), StubDependencyFactory::stubMigration());
                }
                return new AvailableMigrationsList($items);
            }

            public function getPlanUntilVersion(Version $to): \Doctrine\Migrations\Metadata\MigrationPlanList
            {
                throw new \RuntimeException('not used by MigrationHealthService');
            }

            public function getPlanForVersions(array $versions, string $direction): \Doctrine\Migrations\Metadata\MigrationPlanList
            {
                throw new \RuntimeException('not used by MigrationHealthService');
            }
        };
    }

    public function getMetadataStorage(): MetadataStorage
    {
        // ALWAYS throw if a throwable is configured, regardless of
        // throwOnlyOnStorage mode — this is the whole point of that
        // mode: simulate "metadata storage is broken, everything else
        // works".
        $this->maybeThrow(true);
        $cfg = $this->config;

        return new class($cfg) implements MetadataStorage {
            public function __construct(private array $c) {}

            public function getExecutedMigrations(): ExecutedMigrationsList
            {
                $items = [];

                // If `availableVersions` is set, align the executed
                // list to match so the diff calculation works.
                if (!empty($this->c['availableVersions'])) {
                    $avail = $this->c['availableVersions'];
                    $execCount = $this->c['executed'] ?? count($avail);
                    // Use the last `executed` versions from availableVersions.
                    $execList = array_slice($avail, max(0, count($avail) - $execCount));
                    foreach ($execList as $v) {
                        $items[] = new ExecutedMigration(new Version($v));
                    }
                    // Append the unavailable versions if specified.
                    foreach ($this->c['unavailable'] ?? [] as $v) {
                        $items[] = new ExecutedMigration(new Version($v));
                    }
                    return new ExecutedMigrationsList($items);
                }

                // Otherwise build synthetic versions.
                $latest = $this->c['latestExecuted'] ?? 'Version20260924000000';
                $base = substr($latest, 0, -4);
                $rev  = (int) substr($latest, -4);
                for ($i = 0; $i < $this->c['executed']; $i++) {
                    $seq = $rev - ($this->c['executed'] - 1 - $i);
                    $ver = sprintf('%s%04d', $base, $seq);
                    $items[] = new ExecutedMigration(new Version($ver));
                }
                return new ExecutedMigrationsList($items);
            }

            public function reset(): void
            {
                // no-op
            }

            public function ensureInitialized(): void
            {
                // no-op
            }

            public function complete(\Doctrine\Migrations\Version\ExecutionResult $result): void
            {
                // no-op
            }
        };
    }

    /**
     * Return the stubbed Connection (for raw-SQL fallback tests).
     *
     * The MigrationHealthService calls this when getMetadataStorage()
     * throws. For all other tests, getConnection() should never be
     * called — the service's happy path doesn't need it.
     *
     * Implementation: a real Connection has a complex constructor
     * requiring a Driver object we don't have in tests. We bypass it
     * with reflection. The anonymous class below extends Connection
     * and overrides fetchAllAssociative() to return rawRows. Other
     * methods will fail if called — that's the contract.
     */
    public function getConnection(): \Doctrine\DBAL\Connection
    {
        $ref = new \ReflectionClass(\Doctrine\DBAL\Connection::class);
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $ref->newInstanceWithoutConstructor();

        $rows = $this->rawRows;
        return new class($conn, $rows) extends \Doctrine\DBAL\Connection {
            // @phpstan-ignore-next-line method.childParameterType
            public function __construct(\Doctrine\DBAL\Connection $inner, array $rows)
            {
                // Skip parent::__construct (Driver object not needed for tests).
                $this->stubRows = $rows;
            }
            public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array
            {
                return $this->stubRows;
            }
            private array $stubRows = [];
        };
    }

    /**
     * Rows returned by the stubbed Connection's fetchAllAssociative.
     * @var array<int, array<string, string>>
     */
    public array $rawRows = [];

    public static function stubMigration(): \Doctrine\Migrations\AbstractMigration
    {
        // AbstractMigration's constructor requires a Connection and a
        // LoggerInterface. The migration is never executed in tests,
        // so we pass a dummy connection + NullLogger.
        return new class(new \Doctrine\DBAL\Connection([], new \Doctrine\DBAL\Driver\PDO\SQLite\Driver()), new \Psr\Log\NullLogger()) extends \Doctrine\Migrations\AbstractMigration {
            public function up(\Doctrine\DBAL\Schema\Schema $schema): void
            {
                // no-op stub for tests.
            }
            public function down(\Doctrine\DBAL\Schema\Schema $schema): void
            {
                // no-op stub for tests.
            }
        };
    }
}