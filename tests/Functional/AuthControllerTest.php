<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\Auth\JwtService;
use App\Service\Auth\RefreshTokenService;

class AuthControllerTest extends ApiTestCase
{
    public function testLoginWithValidCodeAndNameReturnsToken(): void
    {
        $user = $this->createUser(['code' => 'AUTH001', 'name' => 'Auth Tester', 'password' => 'TestPass123!']);

        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'auth001',
            'name' => 'Auth Tester',
        ]);

        $this->assertSame(200, $result['status'], 'Body: ' . json_encode($result['data']));
        $this->assertTrue($result['data']['success'] ?? false);
        $this->assertNotEmpty($result['data']['token'] ?? null);
        $this->assertSame(900, $result['data']['expires_in'] ?? null);
        $this->assertSame('AUTH001', $result['data']['user']['code'] ?? null);
        $this->assertFalse($result['data']['user']['isAdmin']);
    }

    public function testLoginWithEmptyCodeReturns401(): void
    {
        $result = $this->jsonRequest('POST', '/api/auth/login', ['name' => 'Test']);
        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['data']['success'] ?? true);
    }

    public function testLoginWithInvalidCodeReturns401(): void
    {
        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'NOPE_NOT_REAL',
            'name' => 'Nobody',
        ]);
        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['data']['success'] ?? true);
    }

    public function testLoginWithInactiveUserReturns401(): void
    {
        $this->createUser(['code' => 'INACT01', 'name' => 'Inactive', 'active' => false]);

        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'INACT01',
            'name' => 'Inactive',
        ]);
        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['data']['success'] ?? true);
    }

    public function testAdminLoginRequiresPassword(): void
    {
        $admin = $this->createAdmin(['code' => 'ADM_LOGIN', 'name' => 'Admin Login']);

        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'ADM_LOGIN',
            'name' => 'Admin Login',
        ]);
        $this->assertSame(401, $result['status']);
    }

    public function testAdminLoginWithCorrectPasswordSucceeds(): void
    {
        $admin = $this->createAdmin(['code' => 'ADM_PW', 'name' => 'Admin PW', 'password' => 'AdminPass123!']);

        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'ADM_PW',
            'password' => 'AdminPass123!',
        ]);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['success'] ?? false);
        $this->assertTrue($result['data']['user']['isAdmin']);
        $this->assertNotEmpty($result['data']['token'] ?? null);
    }

    public function testAdminLoginWithWrongPasswordReturns401(): void
    {
        $this->createAdmin(['code' => 'ADM_WRONG', 'name' => 'Admin Wrong', 'password' => 'RightPass123!']);

        $result = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'ADM_WRONG',
            'password' => 'WrongPass123!',
        ]);
        $this->assertSame(401, $result['status']);
    }

    public function testRefreshTokenIsIssuedAtLogin(): void
    {
        $user = $this->createUser(['code' => 'REFRESH01', 'name' => 'Refresh', 'password' => 'TestPass123!']);

        $loginResult = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'REFRESH01',
            'name' => 'Refresh',
        ]);
        $this->assertSame(200, $loginResult['status']);
        $this->assertNotEmpty($loginResult['data']['refresh_token'] ?? null,
            'Refresh token must be issued at login');
        $this->assertSame(64, strlen($loginResult['data']['refresh_token']),
            'Refresh token must be 64 hex chars (32 random bytes)');
    }

    public function testRefreshAfterRotationLockWindowIssuesNewToken(): void
    {
        $user = $this->createUser(['code' => 'REFRESH03', 'name' => 'Refresh3', 'password' => 'TestPass123!']);

        $loginResult = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'REFRESH03',
            'name' => 'Refresh3',
        ]);
        $this->assertSame(200, $loginResult['status']);
        $refreshToken = $loginResult['data']['refresh_token'] ?? null;
        $this->assertNotNull($refreshToken);

        // Bypass the 5-second rotation lock by manually resetting the timestamp
        $user->setRefreshTokenRotatedAt(new \DateTimeImmutable('-10 seconds'));
        $this->em->flush();

        $refreshResult = $this->jsonRequest('POST', '/api/auth/refresh', [
            'code' => 'REFRESH03',
            'refresh_token' => $refreshToken,
        ]);
        $this->assertSame(200, $refreshResult['status']);
        $this->assertTrue($refreshResult['data']['success'] ?? false);
        $this->assertNotEmpty($refreshResult['data']['token']);
        $this->assertNotEquals($refreshToken, $refreshResult['data']['refresh_token'],
            'Refresh token must ROTATE on each use');
    }

    public function testRefreshWithInvalidTokenReturns401(): void
    {
        $this->createUser(['code' => 'REFRESH02', 'name' => 'Refresh2']);

        $result = $this->jsonRequest('POST', '/api/auth/refresh', [
            'code' => 'REFRESH02',
            'refresh_token' => 'invalid-token-here',
        ]);
        $this->assertSame(401, $result['status']);
    }

    public function testCheckReturnsAuthenticatedTrueWithBearer(): void
    {
        $user = $this->createUser(['code' => 'CHECK01', 'name' => 'Check']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/api/auth/check',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $result = $this->parseJsonResponse();
        $this->assertTrue($result['data']['authenticated'] ?? false);
        $this->assertSame('CHECK01', $result['data']['user']['code'] ?? null);
    }

    public function testLogoutClearsSession(): void
    {
        $user = $this->createUser(['code' => 'LOGOUT01', 'name' => 'Logout']);
        $this->loginAs($user);

        $result = $this->jsonRequest('POST', '/api/auth/logout');
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['success'] ?? false);
    }
}