<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CodeAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /** Audit AUDIT-2026-09-28 #8: deben coincidir con AuthController para que
     * el rate-limit funcione tanto en falla (este authenticator) como en
     * éxito (controller). */
    private const LOGIN_RATE_LIMIT_MAX = 5;
    private const LOGIN_RATE_LIMIT_WINDOW = 900;

    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private UrlGeneratorInterface $urlGenerator,
        private \App\Service\RateLimiterService $rateLimiter,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/api/auth/login' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        // Audit AUDIT-2026-09-28: contamos TODOS los intentos (fallidos
        // tambien) para que el 429 dispare tras 5 fallos consecutivos.
        $clientIp = $request->getClientIp() ?? '127.0.0.1';
        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim($data['code'] ?? ''));
        $rlKey = sprintf('login_attempts:%s:%s', $clientIp, $code);
        $remainingBeforeHit = $this->rateLimiter->checkAndHit($rlKey, self::LOGIN_RATE_LIMIT_MAX, self::LOGIN_RATE_LIMIT_WINDOW);
        if ($remainingBeforeHit <= 0) {
            throw new BadCredentialsException('Demasiados intentos. Esperá 15 minutos.');
        }
        // checkAndHit devuelve remaining ANTES del hit. Para el header
        // X-RateLimit-Remaining (cuantos intentos le quedan al cliente),
        // seguimos la convencion de Twitter/GitHub: lo que queda DESPUES de
        // este hit = remainingBeforeHit - 1.
        $remaining = $remainingBeforeHit - 1;
        // Compartimos remaining/reset con el controller (via atributos del
        // request) para que el 200 también pueda emitir los headers.
        $request->attributes->set('_auth_remaining', $remaining);
        $request->attributes->set('_auth_reset_at', time() + self::LOGIN_RATE_LIMIT_WINDOW);
        $request->attributes->set('_auth_rl_key', $rlKey);

        $name = trim($data['name'] ?? '');
        $password = $data['password'] ?? null;

        if (empty($code)) {
            throw new BadCredentialsException('Código de acceso requerido');
        }

        $user = $this->userRepository->findByCode($code);

        if (!$user || !$user->isActive()) {
            throw new BadCredentialsException('Código inválido o desactivado');
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            if (empty($password)) {
                throw new BadCredentialsException('Contraseña requerida para administradores');
            }
            // ⛧ Fix 2026-08-01: validar password manualmente en vez de usar
            // PasswordCredentials (que dispara el mensaje genérico
            // "The presented password is invalid." de Symfony en lugar
            // del mensaje custom que queremos).
            if (!$this->passwordHasher->isPasswordValid($user, $password)) {
                throw new BadCredentialsException('Contraseña incorrecta');
            }
            // Admin: ya validamos password, no requiere 'name'
            return new SelfValidatingPassport(new UserBadge($code));
        }

        if (strcasecmp(trim($user->getName()), $name) !== 0) {
            throw new BadCredentialsException('Nombre de usuario incorrecto');
        }

        return new SelfValidatingPassport(new UserBadge($code));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        $user->setLastLogin(new \DateTimeImmutable());
        $this->userRepository->getEntityManager()->flush();

        // Reset del rate-limit tras un login exitoso: si llegamos aca es que
        // el usuario demostro conocer sus credenciales.
        $rlKey = $request->attributes->get('_auth_rl_key');
        if (is_string($rlKey) && $rlKey !== '') {
            $this->rateLimiter->reset($rlKey);
        }

        // Return null so the AuthController can build its own response
        // (which includes the JWT token + refresh_token).
        // Returning a Response here would OVERRIDE the controller's response.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Audit AUDIT-2026-09-28: distinguir entre 429 (rate limit) y 401
        // (credenciales invalidas). Symfony mapea BadCredentialsException a
        // 401 por default — pero cuando el mensaje indica rate-limit, debe
        // ser 429.
        $isRateLimit = str_contains($exception->getMessage(), 'Demasiados intentos');
        $status = $isRateLimit ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNAUTHORIZED;

        $payload = [
            'success' => false,
            'error' => $exception->getMessage(),
        ];
        if ($isRateLimit) {
            $payload['error_code'] = 'rate_limit_exceeded';
            $payload['retry_after'] = self::LOGIN_RATE_LIMIT_WINDOW;
        }
        $response = new JsonResponse($payload, $status);

        // Headers X-RateLimit-* (audit #8) — el cliente debe saber cuantos
        // intentos le quedan antes del 429.
        $remaining = (int) $request->attributes->get('_auth_remaining', 0);
        $resetAt = (int) $request->attributes->get('_auth_reset_at', time() + self::LOGIN_RATE_LIMIT_WINDOW);
        $response->headers->set('X-RateLimit-Limit', (string) self::LOGIN_RATE_LIMIT_MAX);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));
        $response->headers->set('X-RateLimit-Reset', (string) $resetAt);
        if ($isRateLimit) {
            $response->headers->set('Retry-After', (string) self::LOGIN_RATE_LIMIT_WINDOW);
        }

        return $response;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $path = $request->getPathInfo();
        $expectsJson = $request->isXmlHttpRequest()
            || in_array('application/json', $request->getAcceptableContentTypes(), true)
            || str_starts_with($path, '/api/')
            || str_starts_with($path, '/sanctum/api/');

        if ($expectsJson) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Se requiere autenticación',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }
}
