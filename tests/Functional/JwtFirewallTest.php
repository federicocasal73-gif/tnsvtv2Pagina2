<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Auth\JwtService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class JwtFirewallTest extends ApiTestCase
{
    public function testBearerTokenAuthenticatesUser(): void
    {
        $user = $this->createUser(['code' => 'JWTUSER', 'name' => 'JWT Tester']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/api/auth/check',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['authenticated'] ?? false, 'User must be authenticated via JWT');
        $this->assertSame('JWTUSER', $data['user']['code'] ?? null);
        $this->assertSame('JWT Tester', $data['user']['name'] ?? null);
    }

    public function testInvalidBearerTokenReturns401WithSpanishError(): void
    {
        $this->client->request(
            'GET',
            '/api/feed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-jwt-token'],
        );

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success'] ?? true);
        $this->assertSame('invalid_token', $body['error_code'] ?? null);
        $this->assertSame('1', $response->headers->get('X-TNSVT-Error'));
    }

    public function testExpiredTokenReturns401(): void
    {
        $user = $this->createUser(['code' => 'EXPIRED01', 'name' => 'Expired']);
        $token = $this->buildExpiredToken($user);

        $this->client->request(
            'GET',
            '/api/feed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testJwtTokenContainsExpectedClaims(): void
    {
        $user = $this->createUser(['code' => 'CLAIMS01', 'name' => 'Claims Tester']);
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $token = $jwtManager->create($user);

        $payload = $this->decodeJwtPayload($token);

        $this->assertSame('CLAIMS01', $payload['username'] ?? null, 'username claim must be the user code');
        $this->assertContains('ROLE_USER', $payload['roles'] ?? []);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertSame(900, $payload['exp'] - $payload['iat'], 'TTL should be 15 minutes');
    }

    public function testAuthenticatedUserCanAccessProtectedEndpoint(): void
    {
        $user = $this->createUser(['code' => 'FEEDUSER']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/api/feed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        $this->assertNotSame(401, $response->getStatusCode(), 'Must not return 401 with valid token');
    }

    public function testJwtAuthenticatorIsHigherPriorityThanCodeAuthenticator(): void
    {
        $user = $this->createUser(['code' => 'PRIOUSER', 'name' => 'Priority']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/api/auth/check',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['authenticated'] ?? false);

        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => 'PRIOUSER', 'name' => 'Priority']),
        );
        $loginResponse = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($loginResponse['success'] ?? false, 'CodeAuthenticator still works for login');
    }

    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT must have 3 parts');
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }

    private function buildExpiredToken(\App\Entity\User $user): string
    {
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');
        return $encoder->encode([
            'username' => $user->getCode(),
            'roles' => $user->getRoles(),
            'iat' => time() - 1200,
            'exp' => time() - 60,
        ]);
    }
}