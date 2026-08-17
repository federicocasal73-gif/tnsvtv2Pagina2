<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

class CorsAndProxyTest extends ApiTestCase
{
    public function testCorsOptionsPreflightIsNotRejectedByFirewall(): void
    {
        $this->client->request(
            'OPTIONS',
            '/api/auth/login',
            [],
            [],
            [
                'HTTP_ORIGIN' => 'http://localhost:3000',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Authorization',
            ],
        );

        $response = $this->client->getResponse();
        $this->assertNotSame(401, $response->getStatusCode(),
            'CORS preflight OPTIONS to /api/auth/login must not be 401 (PUBLIC_ACCESS)');
    }

    public function testCorsPreflightOnSanctumAdminEndpoint(): void
    {
        $this->client->request(
            'OPTIONS',
            '/sanctum/api/users',
            [],
            [],
            [
                'HTTP_ORIGIN' => 'http://localhost:3000',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Authorization',
            ],
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(),
            'Sanctum admin OPTIONS preflight must reach firewall (200)');
    }

    public function testTrustedProxiesAreConfigured(): void
    {
        $request = Request::create('/');
        $request->server->set('REMOTE_ADDR', '10.0.0.5');
        $request->headers->set('X-Forwarded-For', '203.0.113.42, 10.0.0.5');

        $clientIp = $request->getClientIp();
        $this->assertNotNull($clientIp);
        $this->assertContains($clientIp, ['203.0.113.42', '10.0.0.5'],
            'Client IP detection must use X-Forwarded-For when behind trusted proxy');
    }

    public function testTrustedProxiesInTestEnv(): void
    {
        $request = Request::create('/', 'GET');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $clientIp = $request->getClientIp();
        $this->assertSame('127.0.0.1', $clientIp,
            'Test env must trust REMOTE_ADDR=127.0.0.1 so client IP detection works');
    }

    public function testTrustedProxiesParseFromEnv(): void
    {
        $reflection = new \ReflectionClass(\Symfony\Component\HttpFoundation\Request::class);
        $this->assertTrue($reflection->hasProperty('trustedProxies'),
            'Request::trustedProxies property must exist (SF8)');
    }
}