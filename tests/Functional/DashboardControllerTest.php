<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Auth\JwtService;

/**
 * Regression test for B3 (DashboardController tournament_trades):
 * The endpoint queried `tournament_trades.pnl_usd` — that table was
 * dropped by Version20260822000000 when the tournaments subsystem
 * was deprecated. On MySQL prod the query fails with
 * "Base table or view not found: tournament_trades".
 * Fix: return globalPnl=0 + a warning field. The endpoint stays
 * 200 OK and admins see why the KPI is N/D.
 *
 * Bonus: the rest of the dashboard queries were rewritten from raw
 * MySQL SQL (`DATE_SUB(NOW(), INTERVAL ...)`) to Doctrine DQL so the
 * endpoint is now portable across sqlite (CI) and MySQL (prod).
 */
final class DashboardControllerTest extends ApiTestCase
{
    public function testDashboardReturnsGlobalPnlWarning(): void
    {
        $admin = $this->createAdmin(['code' => 'DASH01']);
        $token = static::getContainer()->get(JwtService::class)->createToken($admin);

        $this->client->request(
            'GET',
            '/sanctum/api/dashboard',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Dashboard endpoint must not 500 in prod. Body: ' . $response->getContent()
        );

        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['success'] ?? false);
        self::assertArrayHasKey('kpis', $data);
        self::assertSame(0, (int) $data['kpis']['globalPnl']);
        self::assertSame(
            'tournament_trades_subsystem_deprecated',
            $data['kpis']['globalPnlWarning']
        );
        self::assertNotEmpty($data['kpis']['globalPnlWarningDetail']);
    }

    public function testDashboardRequiresAdmin(): void
    {
        $user = $this->createUser(['code' => 'DASHU01']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/sanctum/api/dashboard',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [401, 403], 'Non-admin must be denied access');
    }
}
