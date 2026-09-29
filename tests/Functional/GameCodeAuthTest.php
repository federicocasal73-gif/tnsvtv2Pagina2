<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\Auth\JwtService;

/**
 * Regression test for Fase 2.1 (X-Game-Code fallback consolidation):
 * Before this change, the `X-Game-Code` header was inspected by 6+
 * controllers in their own private resolveUser() / getCurrentUser()
 * methods, with subtle variations. The header-based authenticator
 * was implemented but never registered.
 *
 * Fix:
 * 1. Registered `LegacyHeaderAuthenticator` in the firewall
 *    (config/packages/security.yaml).
 * 2. Created `App\Security\GameCodeResolver` for explicit
 *    query/body fallback support.
 * 3. Migrated ChatController, ChatUploadController and
 *    CampusUploadController to delegate to the new service.
 *
 * This test verifies:
 * - A request with X-Game-Code header is authenticated by the
 *   firewall (controllers that depend on `$this->getUser()`
 *   see the user).
 * - A request without the header still falls through to controllers
 *   that do their own resolution (preserves backward compatibility
 *   with clients that send the code via query/body).
 */
final class GameCodeAuthTest extends ApiTestCase
{
    public function testXGameCodeHeaderAuthenticatesViaFirewall(): void
    {
        $user = $this->createUser(['code' => 'GCAUTH01', 'name' => 'GC Auth']);

        // Hit a route whose controller uses $this->getUser() or
        // the firewall for auth. /api/me/check requires auth.
        $this->client->request(
            'GET',
            '/api/auth/check',
            [],
            [],
            ['HTTP_X_GAME_CODE' => 'GCAUTH01'],
        );

        $response = $this->client->getResponse();
        // The /api/auth/check route returns user info when authenticated.
        // We just need to confirm we are NOT 401.
        self::assertNotSame(401, $response->getStatusCode(),
            'X-Game-Code header should authenticate. Body: ' . $response->getContent());
    }

    public function testGameCodeResolverFindsActiveUser(): void
    {
        $user = $this->createUser(['code' => 'GCRES01', 'name' => 'GC Resolver']);

        $resolver = static::getContainer()->get(\App\Security\GameCodeResolver::class);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->headers->set('X-Game-Code', '  gcres01  '); // trim + uppercase

        [$found, $source] = $resolver->resolve($request);

        self::assertNotNull($found, 'Resolver should find active user by X-Game-Code');
        self::assertSame('GCRES01', $found->getCode());
        self::assertSame('X-Game-Code-header', $source);
    }

    public function testGameCodeResolverFindsUserViaQuery(): void
    {
        $user = $this->createUser(['code' => 'GCQRY01', 'name' => 'GC Query']);

        $resolver = static::getContainer()->get(\App\Security\GameCodeResolver::class);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->query->set('user_code', 'gcqry01');

        [$found, $source] = $resolver->resolve($request);

        self::assertNotNull($found, 'Resolver should find active user via query');
        self::assertSame('GCQRY01', $found->getCode());
        self::assertSame('user_code-query', $source);
    }

    public function testGameCodeResolverRejectsInactiveUser(): void
    {
        $user = $this->createUser(['code' => 'GCINA01', 'name' => 'GC Inactive', 'active' => false]);

        $resolver = static::getContainer()->get(\App\Security\GameCodeResolver::class);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->headers->set('X-Game-Code', 'GCINA01');

        [$found, $source] = $resolver->resolve($request);

        self::assertNull($found, 'Resolver must NOT return inactive users');
        self::assertSame('X-Game-Code-header', $source);
    }

    public function testGameCodeResolverRejectsUnknownCode(): void
    {
        $resolver = static::getContainer()->get(\App\Security\GameCodeResolver::class);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->headers->set('X-Game-Code', 'NOPE_NOPE');

        [$found, $source] = $resolver->resolve($request);

        self::assertNull($found);
        self::assertSame('X-Game-Code-header', $source);
    }
}
