<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Legacy Header Authenticator for backward compat with X-Game-Code header.
 *
 * Many controllers in the parent project use `X-Game-Code` header to identify
 * the current user. This authenticator bridges that pattern so v2 keeps the
 * same semantics without breaking every controller.
 *
 * Priority 2: runs after JwtAuthenticator (priority 1).
 *
 * Flow:
 * 1. Read X-Game-Code header from request
 * 2. If present, look up user by code
 * 3. If user exists and is active, set as authenticated
 * 4. If header absent or invalid, returns null (lets next authenticator try)
 */
class LegacyHeaderAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-Game-Code');
    }

    public function authenticate(Request $request): Passport
    {
        $code = strtoupper(trim($request->headers->get('X-Game-Code', '')));

        if (empty($code)) {
            throw new CustomUserMessageAuthenticationException('X-Game-Code header está vacío');
        }

        $user = $this->userRepository->findByCode($code);

        if (!$user || !$user->isActive()) {
            throw new UserNotFoundException(sprintf('Código inválido o desactivado: %s', $code));
        }

        return new SelfValidatingPassport(new UserBadge($code, fn() => $user));
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?\Symfony\Component\HttpFoundation\Response
    {
        return null; // continue to controller
    }

    public function onAuthenticationFailure(Request $request, \Symfony\Component\Security\Core\Exception\AuthenticationException $exception): ?\Symfony\Component\HttpFoundation\Response
    {
        $body = json_encode([
            'success' => false,
            'error' => $exception->getMessage() ?: 'Código inválido o desactivado',
            'error_code' => 'invalid_code',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = new \Symfony\Component\HttpFoundation\Response($body, 401);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Length', (string) strlen($body));
        $response->headers->set('X-TNSVT-Error', '1');

        return $response;
    }
}