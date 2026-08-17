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
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/api/auth/login' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim($data['code'] ?? ''));
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

        // Return null so the AuthController can build its own response
        // (which includes the JWT token + refresh_token).
        // Returning a Response here would OVERRIDE the controller's response.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
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
