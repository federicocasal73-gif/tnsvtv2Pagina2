<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Audit AUDIT-2026-09-28 #10: cobertura incompleta del rate-limiter.
 * Verifica que tras N intentos invalidos consecutivos, /api/auth/login
 * devuelve 429 Too Many Requests.
 *
 * Configuracion actual (ver CodeAuthenticator::LOGIN_RATE_LIMIT_*):
 *   - Max: 5 intentos
 *   - Window: 900s (15 min)
 *
 * El test envia 8 intentos invalidos y verifica que:
 *   - Los primeros 5 devuelven 401 con X-RateLimit-Remaining decreciente
 *   - Del intento 6 en adelante, devuelven 429 con error 'rate_limit_exceeded'
 */
class LoginRateLimitLoadTest extends ApiTestCase
{
    public function testRateLimitTriggersAfterMaxAttempts(): void
    {
        // Reset para no contaminar con tests previos.
        $rl = self::getContainer()->get(\App\Service\RateLimiterService::class);
        $rlKey = 'login_attempts:127.0.0.1:RATELIMIT01';
        $rl->reset($rlKey);

        // Necesitamos un user valido para que el codigo no sea "missing"
        // y la cuenta falle por rate-limit (no por "codigo no existe").
        // Pero queremos que la autenticacion FALLE, asi que enviamos
        // password incorrecto.
        $this->createUser([
            'code' => 'RATELIMIT01',
            'name' => 'Rate Limit User',
            'password' => 'correct-password',
            'roles' => ['ROLE_ADMIN'],
        ]);

        $statuses = [];
        $remainingHeaders = [];

        for ($i = 1; $i <= 8; $i++) {
            $this->client->request(
                'POST',
                '/api/auth/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'code' => 'ratelimit01',
                    'password' => 'WRONG-password',
                ], JSON_THROW_ON_ERROR),
            );
            $response = $this->client->getResponse();
            $statuses[] = $response->getStatusCode();
            $remainingHeaders[] = $response->headers->get('X-RateLimit-Remaining');
        }

        // Primeros 5: 401 (credenciales invalidas).
        // Del 6 en adelante: 429 (rate limit exceeded).
        $this->assertSame([401, 401, 401, 401, 401, 429, 429, 429], $statuses);

        // Remaining debe decrecer de 4 a 0 en los primeros 5, luego 0 en 429s.
        $this->assertSame(['4', '3', '2', '1', '0', '0', '0', '0'], $remainingHeaders);

        // X-RateLimit-Limit siempre 5.
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertSame('5', $this->client->getResponse()->headers->get('X-RateLimit-Limit'));
    }

    public function testSuccessfulLoginResetsCounter(): void
    {
        $rl = self::getContainer()->get(\App\Service\RateLimiterService::class);
        $rlKey = 'login_attempts:127.0.0.1:RESETOK01';
        $rl->reset($rlKey);

        $this->createUser(['code' => 'RESETOK01', 'name' => 'Reset OK']);

        // 3 intentos fallidos.
        for ($i = 0; $i < 3; $i++) {
            $this->jsonRequest('POST', '/api/auth/login', [
                'code' => 'resetok01',
                'name' => 'WRONG NAME',
            ]);
        }

        // Ahora uno correcto: el rate-limit se resetea.
        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'resetok01',
            'name' => 'Reset OK',
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('5', $this->client->getResponse()->headers->get('X-RateLimit-Remaining'));

        // Tras reset, podemos hacer 4 fallos antes del 429 (no 1).
        for ($i = 0; $i < 4; $i++) {
            $this->jsonRequest('POST', '/api/auth/login', [
                'code' => 'resetok01',
                'name' => 'WRONG NAME',
            ]);
        }
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame('1', $this->client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }
}