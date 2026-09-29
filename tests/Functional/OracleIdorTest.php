<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Auth\JwtService;

/**
 * Regression test for B5 (OracleController IDOR):
 * Before this fix, the Oracle endpoints accepted ?code=ANYONE and
 * returned that user's trading psychology data (emotional bias,
 * faith vs logic, session performance) to any authenticated user.
 *
 * Fix: `resolveUserCode()` now returns 403 AccessDenied when a
 * non-admin caller asks for a code that differs from their own.
 */
final class OracleIdorTest extends ApiTestCase
{
    public function testUserCanQueryOwnMetricsWithoutCode(): void
    {
        $user = $this->createUser(['code' => 'ORACLE_OWN', 'name' => 'Owner']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/sanctum/api/oracle/emotional-bias',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('ORACLE_OWN', $data['user']);
    }

    public function testUserCanQueryOwnMetricsWithMatchingCode(): void
    {
        $user = $this->createUser(['code' => 'ORACLE_OWN2', 'name' => 'Owner2']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/sanctum/api/oracle/emotional-bias?code=ORACLE_OWN2',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode(),
            'Self-code should be allowed. Body: ' . $this->client->getResponse()->getContent());
    }

    public function testUserCannotQueryOtherUserMetrics(): void
    {
        $attacker = $this->createUser(['code' => 'ORACLE_EVIL', 'name' => 'Attacker']);
        $victim = $this->createUser(['code' => 'ORACLE_VICTIM', 'name' => 'Victim']);
        $token = static::getContainer()->get(JwtService::class)->createToken($attacker);

        foreach (['emotional-bias', 'faith-logic', 'session-performance'] as $endpoint) {
            $this->client->request(
                'GET',
                '/sanctum/api/oracle/' . $endpoint . '?code=ORACLE_VICTIM',
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            );

            self::assertSame(
                403,
                $this->client->getResponse()->getStatusCode(),
                sprintf('Endpoint %s must return 403 when attacker queries another user', $endpoint)
            );
        }
    }

    public function testAdminCanQueryAnyUserMetrics(): void
    {
        $admin = $this->createAdmin(['code' => 'ORACLE_ADM', 'name' => 'Admin']);
        $victim = $this->createUser(['code' => 'ORACLE_SUBJECT', 'name' => 'Subject']);
        $token = static::getContainer()->get(JwtService::class)->createToken($admin);

        $this->client->request(
            'GET',
            '/sanctum/api/oracle/emotional-bias?code=ORACLE_SUBJECT',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode(),
            'Admin should be able to query any user. Body: ' . $this->client->getResponse()->getContent());
    }
}
