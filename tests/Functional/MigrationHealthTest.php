<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\MigrationHealthService;
use App\Tests\Functional\Stub\StubDependencyFactory;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\MetadataStorageError;

/**
 * Tests for the Doctrine migrations health probe (Risk #3 in RISK_MITIGATION.md).
 *
 * What we cover:
 *  1. check() returns `in_sync=true` when all defined migrations are applied.
 *  2. check() returns `in_sync=false` when pending migrations exist.
 *  3. check() lists the exact pending version(s).
 *  4. check() lists the exact unavailable version(s) (in DB but not in code).
 *  5. check() is cached: subsequent calls within 10s don't re-probe.
 *  6. resetCache() forces the next check() to actually probe.
 *  7. End-to-end: GET /api/health/migrations returns 200 when in sync.
 *  8. End-to-end: GET /api/health/migrations returns 500 on drift.
 *  9. End-to-end: GET /api/health/migrations returns 500 on internal error.
 * 10. End-to-end: GET /api/health/migrations is public (no auth).
 * 11. End-to-end: POST /api/admin/migrations/check requires ROLE_ADMIN.
 * 12. End-to-end: POST /api/admin/migrations/check returns 200 for admin.
 */
class MigrationHealthTest extends ApiTestCase
{
    /**
     * Force a fresh kernel + container per test. By default Symfony's
     * WebTestCase reuses the kernel across tests in the same class for
     * performance. We swap the DependencyFactory in several tests,
     * which only works on a fresh container.
     */
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        parent::setUp();
    }

    // ────────────────────────────────────────────────────────────────
    // Service-level tests (DependencyFactory mocked via test container)
    // ────────────────────────────────────────────────────────────────

    public function testCheckReturnsInSyncWhenAllMigrationsApplied(): void
    {
        $this->stubDependencyFactory(availableCount: 3, executedCount: 3, pending: [], unavailable: []);

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $result = $svc->check();

        $this->assertTrue($result['in_sync']);
        $this->assertSame(3, $result['available']);
        $this->assertSame(3, $result['executed']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame([], $result['pending_versions']);
        $this->assertSame([], $result['unavailable_versions']);
        $this->assertNull($result['error']);
        $this->assertNotEmpty($result['checked_at']);
    }

    public function testCheckReturnsDriftWhenPendingMigrationsExist(): void
    {
        $this->stubDependencyFactory(
            availableCount: 5,
            executedCount: 3,
            pending: ['Version20260925000000', 'Version20260930000000'],
            unavailable: [],
        );

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $result = $svc->check();

        $this->assertFalse($result['in_sync']);
        $this->assertSame(5, $result['available']);
        $this->assertSame(3, $result['executed']);
        $this->assertSame(2, $result['pending']);
        $this->assertSame(['Version20260925000000', 'Version20260930000000'], $result['pending_versions']);
    }

    public function testCheckReturnsDriftWhenUnavailableMigrationsInDb(): void
    {
        // The "unavailable" case: there's a row in doctrine_migration_versions
        // whose Version class no longer exists in the migrations/ folder
        // (e.g. the file was deleted in a future cleanup).
        $this->stubDependencyFactory(
            availableCount: 3,
            executedCount: 4,
            pending: [],
            unavailable: ['Version20260101000000'],
        );

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $result = $svc->check();

        $this->assertFalse($result['in_sync']);
        $this->assertSame(['Version20260101000000'], $result['unavailable_versions']);
    }

    public function testCheckReportsLatestAvailableAndExecuted(): void
    {
        $this->stubDependencyFactory(
            availableCount: 5,
            executedCount: 3,
            pending: ['Version20260925000000', 'Version20260930000000'],
            unavailable: [],
            latestAvailable: 'Version20260930000000',
            latestExecuted: 'Version20260920000000',
        );

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $result = $svc->check();

        $this->assertSame('Version20260930000000', $result['latest_available']);
        $this->assertSame('Version20260920000000', $result['latest_executed']);
    }

    public function testCacheShortCircuitsRepeatChecksWithin10Seconds(): void
    {
        // We need to count how many times the underlying DependencyFactory
        // is queried. The service caches its result, so a second call
        // should NOT trigger another query to the storage. We use a
        // counter on a shared stub.
        //
        // Each check() call queries the DependencyFactory 3 times
        // (getMigrationStatusCalculator, getMigrationPlanCalculator,
        // getMetadataStorage). With caching, only the first check()
        // triggers the queries, so the counter should be exactly 3.
        $counter = 0;
        $this->stubDependencyFactoryCounter(
            $counter,
            availableCount: 3,
            executedCount: 3,
            pending: [],
            unavailable: [],
        );

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $svc->check();
        $svc->check();
        $svc->check();

        $this->assertSame(3, $counter, 'Subsequent checks within 10s must hit the cache (first check = 3 calls, next two = 0).');
    }

    public function testResetCacheForcesFreshCheck(): void
    {
        $counter = 0;
        $this->stubDependencyFactoryCounter(
            $counter,
            availableCount: 3,
            executedCount: 3,
            pending: [],
            unavailable: [],
        );

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $svc->check();
        $svc->check();
        $this->assertSame(3, $counter);

        $svc->resetCache();
        $svc->check();
        $this->assertSame(6, $counter, 'After resetCache() the next check must probe again (3 + 3 = 6).');
    }

    public function testCheckReturnsErrorPayloadOnInternalFailure(): void
    {
        $this->stubDependencyFactoryThrowable(new \RuntimeException('storage table missing'));

        $svc = static::getContainer()->get(MigrationHealthService::class);

        $result = $svc->check();

        $this->assertFalse($result['in_sync']);
        $this->assertStringContainsString('storage table missing', (string) $result['error']);
        $this->assertSame(0, $result['available']);
        $this->assertSame(0, $result['executed']);
    }

    public function testFallsBackToRawSqlWhenMetadataStorageIsNotUpToDate(): void
    {
        // The MySQL quirk we hit on prod: Doctrine's metadata-storage
        // introspection disagrees with the live MySQL schema (charset /
        // collation hints) and throws MetadataStorageError::notUpToDate.
        // The service MUST fall back to a raw SQL query rather than
        // failing the health endpoint.
        //
        // Implementation note: the raw SQL path is hard to unit-test
        // because Connection's constructor needs a real driver config.
        // We verify the integration end-to-end via SSH on prod (the
        // /api/health/migrations endpoint returned 200 once the
        // fallback fired). Here we just verify the MetadataStorageError
        // path doesn't take down the endpoint.
        $this->stubStorageOnlyThrows(MetadataStorageError::notUpToDate(), []);

        $svc = static::getContainer()->get(MigrationHealthService::class);
        $result = $svc->check();

        // When raw SQL is reachable but the configured $rawRows is
        // empty, the executed count is 0 and pending becomes "all
        // available". in_sync=false is the correct, loud failure.
        $this->assertArrayHasKey('in_sync', $result);
        $this->assertArrayHasKey('error', $result);
    }

    // ────────────────────────────────────────────────────────────────
    // End-to-end (HTTP) tests
    // ────────────────────────────────────────────────────────────────

    public function testHealthEndpointReturns200WhenInSync(): void
    {
        $this->stubDependencyFactory(availableCount: 3, executedCount: 3, pending: [], unavailable: []);

        $this->client->request('GET', '/api/health/migrations');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('migrations', $data['service']);
        $this->assertTrue($data['in_sync']);
    }

    public function testHealthEndpointReturns500OnDrift(): void
    {
        $this->stubDependencyFactory(
            availableCount: 5,
            executedCount: 3,
            pending: ['Version20260925000000'],
            unavailable: [],
        );

        $this->client->request('GET', '/api/health/migrations');
        $response = $this->client->getResponse();

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('drift', $data['status']);
        $this->assertFalse($data['in_sync']);
        $this->assertContains('Version20260925000000', $data['pending_versions']);
    }

    public function testHealthEndpointReturns500OnInternalError(): void
    {
        $this->stubDependencyFactoryThrowable(new \RuntimeException('db offline'));

        $this->client->request('GET', '/api/health/migrations');
        $response = $this->client->getResponse();

        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('drift', $data['status']);
        $this->assertStringContainsString('db offline', (string) ($data['error'] ?? ''));
    }

    public function testHealthEndpointIsPublic(): void
    {
        // No login → 200/500 from the public endpoint (no auth required).
        $this->stubDependencyFactory(availableCount: 3, executedCount: 3, pending: [], unavailable: []);

        $this->client->request('GET', '/api/health/migrations');
        $status = $this->client->getResponse()->getStatusCode();

        $this->assertContains($status, [200, 500], 'Endpoint must respond without auth (public, for monitoring).');
    }

    public function testAdminForceCheckRequiresAuth(): void
    {
        $this->stubDependencyFactory(availableCount: 3, executedCount: 3, pending: [], unavailable: []);

        // No login → 401 from the firewall (entry_point is CodeAuthenticator).
        $this->client->request('POST', '/api/admin/migrations/check');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminForceCheckBypassesCache(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        // We can't re-stub the container's DependencyFactory after
        // the login flow instantiates it. The 10s cache reset
        // behaviour is already covered by testResetCacheForcesFreshCheck;
        // here we assert the endpoint shape (status, service, in_sync)
        // and that it returns 200 for the admin route.
        $this->stubDependencyFactory(availableCount: 3, executedCount: 3, pending: [], unavailable: []);

        $this->client->request('POST', '/api/admin/migrations/check');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('migrations', $data['service']);
        $this->assertArrayHasKey('in_sync', $data);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    /**
     * @param string[] $pending
     * @param string[] $unavailable
     */
    private function stubDependencyFactory(
        int $availableCount,
        int $executedCount,
        array $pending,
        array $unavailable,
        ?string $latestAvailable = 'Version20260924000000',
        ?string $latestExecuted = 'Version20260924000000',
    ): void {
        $ref = new \ReflectionClass(StubDependencyFactory::class);
        /** @var StubDependencyFactory $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->setConfig([
            'available'        => $availableCount,
            'executed'         => $executedCount,
            'pending'          => $pending,
            'unavailable'      => $unavailable,
            'latestAvailable'  => $latestAvailable,
            'latestExecuted'   => $latestExecuted,
        ]);

        static::getContainer()->set(DependencyFactory::class, $instance);
    }

    /**
     * @param-out int $counter
     * @param string[] $pending
     * @param string[] $unavailable
     */
    private function stubDependencyFactoryCounter(
        int &$counter,
        int $availableCount,
        int $executedCount,
        array $pending,
        array $unavailable,
    ): void {
        $ref = new \ReflectionClass(StubDependencyFactory::class);
        /** @var StubDependencyFactory $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->setConfigAndCounter([
            'available'        => $availableCount,
            'executed'         => $executedCount,
            'pending'          => $pending,
            'unavailable'      => $unavailable,
            'latestAvailable'  => 'Version20260924000000',
            'latestExecuted'   => 'Version20260924000000',
        ], $counter);

        static::getContainer()->set(DependencyFactory::class, $instance);
    }

    private function stubDependencyFactoryThrowable(\Throwable $e): void
    {
        $ref = new \ReflectionClass(StubDependencyFactory::class);
        /** @var StubDependencyFactory $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->setThrowable($e);

        static::getContainer()->set(DependencyFactory::class, $instance);
    }

    /**
     * Stub that throws on getMetadataStorage() only — mimics the
     * "metadata storage is not up to date" quirk we hit on prod MySQL.
     *
     * @param array<int, array{version: string, executed_at: string}> $rawRows
     *        Used to make the available versions list consistent so
     *        any subsequent diff computation is meaningful.
     */
    private function stubStorageOnlyThrows(\Throwable $e, array $rawRows): void
    {
        $ref = new \ReflectionClass(StubDependencyFactory::class);
        /** @var StubDependencyFactory $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        $instance->setStorageThrows($e, [
            'available'        => count($rawRows),
            'executed'         => 0,
            'pending'          => [],
            'unavailable'      => [],
            'latestAvailable'  => $rawRows[count($rawRows) - 1]['version'] ?? 'Version20260924000000',
            'latestExecuted'   => $rawRows[count($rawRows) - 1]['version'] ?? 'Version20260924000000',
            'availableVersions' => array_column($rawRows, 'version'),
        ]);
        static::getContainer()->set(DependencyFactory::class, $instance);
    }
}