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

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): void
    {
        $this->config    = $config;
        $this->throwable = null;
        $this->counter   = null;
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
    }

    public function setThrowable(\Throwable $e): void
    {
        $this->config    = [];
        $this->throwable = $e;
        $this->counter   = null;
    }

    public static function fromConfig(array $config): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->config = $config;

        return $instance;
    }

    private function maybeThrow(): void
    {
        if ($this->throwable !== null) {
            throw $this->throwable;
        }
        if ($this->counter !== null) {
            $this->counter++;
        }
    }

    public function getMigrationStatusCalculator(): \Doctrine\Migrations\Version\MigrationStatusCalculator
    {
        $this->maybeThrow();
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
        $this->maybeThrow();
        $cfg = $this->config;

        return new class($cfg) implements \Doctrine\Migrations\Version\MigrationPlanCalculator {
            public function __construct(private array $c) {}

            public function getMigrations(): AvailableMigrationsList
            {
                $items = [];
                $latest = $this->c['latestAvailable'] ?? 'Version20260924000000';
                // Generate distinct but plausible version strings.
                // We pad with suffix digits so that count() works and
                // getLast() returns a deterministic version.
                $base = substr($latest, 0, -4); // strip last 4 digits
                $rev  = (int) substr($latest, -4);
                for ($i = 0; $i < $this->c['available']; $i++) {
                    // First item is the OLDEST, last item is the LATEST.
                    // We want getLast() to return $latest.
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
        $this->maybeThrow();
        $cfg = $this->config;

        return new class($cfg) implements MetadataStorage {
            public function __construct(private array $c) {}

            public function getExecutedMigrations(): ExecutedMigrationsList
            {
                $items = [];
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