<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests that do NOT require a working database.
 *
 * These hit the routes that the front controller can render with
 * an empty DB (login page, marketing shell, redirect). Routes that
 * load fixtures from DB are intentionally excluded — they need a
 * fixture layer and live in a future tests/Integration suite.
 *
 * Run via:  vendor/bin/phpunit tests/Smoke
 * Or:       APP_ENV=test vendor/bin/phpunit --testsuite smoke
 */
final class StatelessRoutesTest extends WebTestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function statelessRoutesProvider(): iterable
    {
        // [path, expectedStatus]
        yield 'home'              => ['/', 200];
        yield 'login'            => ['/login', 200];
        yield 'sanctum redirect' => ['/sanctum', 302];
    }

    /**
     * @dataProvider statelessRoutesProvider
     */
    public function testRouteResponds(string $path, int $expectedStatus): void
    {
        $client = static::createClient();
        $client->request('GET', $path);
        self::assertSame(
            $expectedStatus,
            $client->getResponse()->getStatusCode(),
            sprintf('GET %s returned %d, expected %d', $path, $client->getResponse()->getStatusCode(), $expectedStatus)
        );
    }
}
