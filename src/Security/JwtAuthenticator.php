<?php

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\JWTAuthenticator as BaseJWTAuthenticator;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * JWT Authenticator for TNSVT Reino v2.
 *
 * Wraps the LexikJWTAuthenticationBundle to provide JWT-based authentication
 * for Sanctum APIs, Trono APK, and Capacitor APKs. Has priority 1 (checked first).
 *
 * Flow:
 * 1. Extract Authorization: Bearer header from request
 * 2. Decode JWT using Lexik's JWTManager
 * 3. If valid, load user from JWT payload (sub/code)
 * 4. Return authenticated Passport
 *
 * If no JWT in request, returns null (lets next authenticator try).
 */
class JwtAuthenticator extends BaseJWTAuthenticator
{
    public function supports(Request $request): ?bool
    {
        // Only support if Authorization Bearer header is present
        return $request->headers->has('Authorization')
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): \Symfony\Component\Security\Http\Authenticator\Passport\Passport
    {
        try {
            return parent::authenticate($request);
        } catch (AuthenticationException $e) {
            throw $e;
        }
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): \Symfony\Component\HttpFoundation\Response
    {
        // TNSVT-style error response: JSON with Spanish messages, explicit Content-Length
        $body = json_encode([
            'success' => false,
            'error' => 'Token inválido o expirado. Volvé a iniciar sesión.',
            'error_code' => 'invalid_token',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = new \Symfony\Component\HttpFoundation\Response($body, 401);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Length', (string) strlen($body));
        $response->headers->set('X-TNSVT-Error', '1');

        return $response;
    }
}