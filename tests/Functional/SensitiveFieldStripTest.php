<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TradingAccount;
use App\Entity\User;
use App\EventListener\SensitiveFieldStripListener;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Tests the SensitiveFieldStripListener (Risk #8 in RISK_MITIGATION.md).
 *
 * What we cover:
 *  1. Top-level sensitive keys are stripped from a JsonResponse.
 *  2. Nested sensitive keys (deep inside arrays/objects) are stripped.
 *  3. Non-JSON responses are NOT touched (HTML, files, images).
 *  4. Already-clean responses pass through unchanged.
 *  5. End-to-end: a controller that returns a User object via $user->toArray()
 *     (hypothetically) gets scrubbed before reaching the client.
 */
class SensitiveFieldStripTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'trading_accounts',
            'journal_entries',
        ]);
    }

    /**
     * Helper: directly invoke the listener against a synthetic response.
     */
    private function strip(array $payload): array
    {
        // Build a kernel.response event in the test client.
        $this->client->request('GET', '/api/auth/check'); // any auth route to seed the request stack
        $request = $this->client->getRequest();
        $response = new JsonResponse($payload);
        $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent(
            static::$kernel ?? $this->client->getKernel(),
            $request,
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $listener = static::getContainer()->get(SensitiveFieldStripListener::class);
        $listener($event);

        return json_decode($response->getContent(), true);
    }

    public function testTopLevelSensitiveKeyIsStripped(): void
    {
        $out = $this->strip([
            'code' => 'USR01',
            'name' => 'Test User',
            'password' => 'leaked-hash',
            'currentRefreshTokenHash' => 'leaked-token',
            'email' => 'leaked@example.com',
        ]);

        $this->assertSame('USR01', $out['code']);
        $this->assertSame('Test User', $out['name']);
        $this->assertArrayNotHasKey('password', $out);
        $this->assertArrayNotHasKey('currentRefreshTokenHash', $out);
        $this->assertArrayNotHasKey('email', $out);
    }

    public function testNestedSensitiveKeysAreStripped(): void
    {
        $out = $this->strip([
            'success' => true,
            'user' => [
                'code' => 'USR02',
                'email' => 'leaked@example.com',
                'meta' => [
                    'lastLoginIp' => '10.0.0.1',
                    'apiKey' => 'leaked',
                    'device' => ['sessionId' => 'abc', 'rememberMeToken' => 'leaked'],
                ],
            ],
            'users' => [
                ['code' => 'A', 'password' => 'leaked'],
                ['code' => 'B', 'email' => 'b@example.com'],
            ],
        ]);

        $this->assertSame('USR02', $out['user']['code']);
        $this->assertArrayNotHasKey('email', $out['user']);
        $this->assertArrayNotHasKey('lastLoginIp', $out['user']['meta']);
        $this->assertArrayNotHasKey('apiKey', $out['user']['meta']);
        // 'sessionId' is intentionally NOT in the strip-list — see the
        // comment on SENSITIVE_KEYS. It's a public integer ID in this
        // codebase, not the PHP session cookie.
        $this->assertSame('abc', $out['user']['meta']['device']['sessionId']);
        $this->assertArrayNotHasKey('rememberMeToken', $out['user']['meta']['device']);
        $this->assertSame('A', $out['users'][0]['code']);
        $this->assertArrayNotHasKey('password', $out['users'][0]);
        $this->assertArrayNotHasKey('email', $out['users'][1]);
    }

    public function testCaseInsensitiveMatching(): void
    {
        $out = $this->strip([
            'Code'        => 'USR03',
            'PASSWORD'    => 'leaked',
            'Email'       => 'leaked',
            'api_key'     => 'leaked',
            'ApiKey'      => 'leaked',
            'lastLoginIP' => 'leaked',
        ]);

        // Only Code (not sensitive) survives.
        $this->assertSame(['Code' => 'USR03'], $out);
    }

    public function testCleanPayloadIsUnchanged(): void
    {
        $payload = [
            'success' => true,
            'user' => ['code' => 'USR04', 'name' => 'Test', 'isAdmin' => false],
            'stats' => ['total' => 5, 'wins' => 3, 'losses' => 2],
        ];
        $out = $this->strip($payload);

        $this->assertSame($payload, $out);
    }

    public function testNonJsonResponseIsUntouched(): void
    {
        // Simulate a Symfony binary-file response (e.g., an image).
        $this->client->request('GET', '/api/auth/check');
        $request = $this->client->getRequest();
        $binary = "\x89PNG\r\n\x1a\nbinary-data";
        $response = new \Symfony\Component\HttpFoundation\Response($binary, 200, ['Content-Type' => 'image/png']);
        $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent(
            static::$kernel ?? $this->client->getKernel(),
            $request,
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $listener = static::getContainer()->get(SensitiveFieldStripListener::class);
        $listener($event);

        $this->assertSame($binary, $response->getContent());
    }

    public function testEndToEndChatUsersEndpointDoesNotLeakSensitiveFields(): void
    {
        // ── Real end-to-end check against /api/chat/users ──
        // Two users exist; response must NEVER contain password / email /
        // refresh-token regardless of how the controller is implemented.
        $u1 = $this->createUser(['code' => 'CHT01', 'name' => 'Chat User 1', 'email' => 'cht01@x.com']);
        $u2 = $this->createUser(['code' => 'CHT02', 'name' => 'Chat User 2', 'email' => 'cht02@x.com']);

        // /api/chat/users requires X-Game-Code header (not Symfony session).
        $this->client->request('GET', '/api/chat/users', server: ['HTTP_X_GAME_CODE' => $u1->getCode()]);
        $r = $this->parseJsonResponse();

        $this->assertSame(200, $r['status']);
        $this->assertIsArray($r['data']);

        $users = $r['data'];
        $this->assertGreaterThanOrEqual(2, count($users));

        foreach ($users as $u) {
            $this->assertArrayNotHasKey('password', $u);
            $this->assertArrayNotHasKey('currentRefreshTokenHash', $u);
            $this->assertArrayNotHasKey('refreshToken', $u);
            $this->assertArrayNotHasKey('apiKey', $u);
            $this->assertArrayNotHasKey('email', $u);
            $this->assertArrayNotHasKey('lastLoginIp', $u);
            // code/name/is_me/is_admin/online/last_activity_at are the only allowed keys.
            foreach (array_keys($u) as $k) {
                $this->assertContains($k, ['code', 'name', 'is_me', 'is_admin', 'online', 'last_activity_at'], "Unexpected key $k in /api/chat/users response");
            }
        }
    }

    public function testEndToEndAuthCheckEndpointDoesNotLeakSensitiveFields(): void
    {
        $user = $this->createUser([
            'code' => 'AUTH01', 'name' => 'Auth User', 'email' => 'auth01@x.com',
        ]);
        $this->loginAs($user);

        $r = $this->jsonRequest('GET', '/api/auth/check');
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['authenticated'] ?? false);

        $userBlock = $r['data']['user'] ?? [];
        $this->assertArrayNotHasKey('password', $userBlock);
        $this->assertArrayNotHasKey('email', $userBlock);
        $this->assertArrayNotHasKey('currentRefreshTokenHash', $userBlock);
        $this->assertArrayNotHasKey('lastLoginIp', $userBlock);
        // Allowed: code, name, isAdmin.
        foreach (array_keys($userBlock) as $k) {
            $this->assertContains($k, ['code', 'name', 'isAdmin'], "Unexpected key $k in /api/auth/check response");
        }
    }
}
