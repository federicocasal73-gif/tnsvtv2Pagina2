<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Contract tests for POST /api/mercure/subscribe-token.
 *
 * Regression cover for the 2026-09-29 prod 500 ("Unknown named parameter
 * $subscribe"): the endpoint must accept the chat widget's auth
 * (X-Game-Code header, same convention as ChatController::resolveUser),
 * read topics from a JSON body, and return a signed token — never a 500.
 */
class MercureSubscribeTokenTest extends ApiTestCase
{
    private function postToken(?string $code, array $body): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $code) {
            $server['HTTP_X_GAME_CODE'] = $code;
        }
        $this->client->request(
            'POST',
            '/api/mercure/subscribe-token',
            [],
            [],
            $server,
            json_encode($body, JSON_THROW_ON_ERROR)
        );

        return $this->parseJsonResponse();
    }

    public function testRequiresAuth(): void
    {
        $r = $this->postToken(null, ['topics' => ['/chat/1']]);

        $this->assertSame(401, $r['status']);
    }

    public function testIssuesTokenWithCodeHeaderAndJsonTopics(): void
    {
        $user = $this->createUser(['code' => 'MRC01', 'name' => 'Mercure User']);

        // Exact shape the chat widget sends (see _fetchMercureToken).
        $r = $this->postToken($user->getCode(), ['topics' => ['/chat/7', '/chat/7/typing']]);

        $this->assertSame(200, $r['status']);
        $this->assertNotEmpty($r['data']['token'] ?? null);
        // JWT compact serialization: header.payload.signature.
        $this->assertSame(2, substr_count((string) $r['data']['token'], '.'));
    }

    public function testIssuesTokenForSessionUserWithoutTopics(): void
    {
        $user = $this->createUser(['code' => 'MRC02', 'name' => 'Session User']);
        $this->loginAs($user);

        $r = $this->jsonRequest('POST', '/api/mercure/subscribe-token', []);

        $this->assertSame(200, $r['status']);
        $this->assertNotEmpty($r['data']['token'] ?? null);
    }

    public function testJunkTopicsAreFilteredNever500(): void
    {
        $user = $this->createUser(['code' => 'MRC03', 'name' => 'Junk Topics']);

        $r = $this->postToken($user->getCode(), [
            'topics' => ['not-a-topic', '', 123, '/chat/9'],
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertNotEmpty($r['data']['token'] ?? null);
    }
}
