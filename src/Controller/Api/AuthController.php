<?php

namespace App\Controller\Api;

use OpenApi\Attributes as OA;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\JwtService;
use App\Service\Auth\RefreshTokenService;
use App\Service\TwoFactorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    private const LOGIN_RATE_LIMIT_MAX = 5;
    private const LOGIN_RATE_LIMIT_WINDOW = 900;

    public function __construct(
        private JwtService $jwtService,
        private RefreshTokenService $refreshTokenService,
        private TwoFactorService $twoFactor,
    ) {}

    private function jsonError(string $message, int $status, string $errorCode = ''): Response
    {
        $payload = ['success' => false, 'error' => $message];
        if ($errorCode !== '') {
            $payload['error_code'] = $errorCode;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = new Response($body, $status);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Length', (string) strlen($body));
        $response->headers->set('X-TNSVT-Error', '1');

        return $response;
    }

    /**
     * Adjunta los headers X-RateLimit-* a una respuesta del endpoint /login.
     * Audit AUDIT-2026-09-28 #8: el cliente debe saber cuantos intentos le quedan
     * antes del 429. Sigue el formato de GitHub/Twitter API.
     *
     * @param Response $response respuesta a la que se le suman headers
     * @param int      $remaining intentos restantes despues de este hit
     * @param int      $resetAt   timestamp UNIX en que la ventana se resetea
     */
    private function withRateLimitHeaders(Response $response, int $remaining, int $resetAt): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) self::LOGIN_RATE_LIMIT_MAX);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));
        $response->headers->set('X-RateLimit-Reset', (string) $resetAt);

        return $response;
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Iniciar sesión',
        description: 'Autentica a un usuario. Los usuarios regulares envían code + name; los administradores envían code + password.',
        tags: ['Autenticación'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login correcto, devuelve JWT + refresh token', content: new OA\JsonContent(ref: '#/components/schemas/LoginResponse')),
            new OA\Response(response: 400, description: 'Código requerido o campos faltantes'),
            new OA\Response(response: 401, description: 'Código inválido o credenciales incorrectas'),
            new OA\Response(response: 429, description: 'Demasiados intentos (rate limit)'),
        ],
    )]
    public function login(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
    ): JsonResponse|Response {
        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim($data['code'] ?? ''));
        $name = trim($data['name'] ?? '');
        $password = $data['password'] ?? null;

        if (empty($code)) {
            return $this->jsonError('Código de acceso requerido', Response::HTTP_BAD_REQUEST, 'code_required');
        }

        // El rate-limit lo gestiona CodeAuthenticator (corre ANTES de este controller).
        // Leemos los atributos que dejó para poder emitir los headers en la respuesta 200.
        $remaining = (int) $request->attributes->get('_auth_remaining', self::LOGIN_RATE_LIMIT_MAX);
        $resetAt = (int) $request->attributes->get('_auth_reset_at', time() + self::LOGIN_RATE_LIMIT_WINDOW);

        $user = $userRepository->findByCode($code);

        if (!$user || !$user->isActive()) {
            return $this->withRateLimitHeaders(
                $this->jsonError('Código inválido o desactivado', Response::HTTP_UNAUTHORIZED, 'invalid_code'),
                $remaining,
                $resetAt,
            );
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            if (empty($password)) {
                return $this->withRateLimitHeaders(
                    $this->jsonError('Contraseña requerida para administradores', Response::HTTP_UNAUTHORIZED, 'admin_password_required'),
                    $remaining,
                    $resetAt,
                );
            }
            if (!$passwordHasher->isPasswordValid($user, $password)) {
                return $this->withRateLimitHeaders(
                    $this->jsonError('Contraseña incorrecta', Response::HTTP_UNAUTHORIZED, 'admin_password_invalid'),
                    $remaining,
                    $resetAt,
                );
            }
        } elseif (strcasecmp(trim($user->getName()), $name) !== 0) {
            return $this->withRateLimitHeaders(
                $this->jsonError('Nombre de usuario incorrecto', Response::HTTP_UNAUTHORIZED, 'name_invalid'),
                $remaining,
                $resetAt,
            );
        } elseif (null !== $user->getPassword() && '' !== $user->getPassword()) {
            // El usuario definió contraseña (perfil o recupero): se exige
            // además del nombre. Usuarios sin password entran como siempre.
            if (empty($password) || !$passwordHasher->isPasswordValid($user, $password)) {
                return $this->withRateLimitHeaders(
                    $this->jsonError('Contraseña incorrecta', Response::HTTP_UNAUTHORIZED, 'password_invalid'),
                    $remaining,
                    $resetAt,
                );
            }
        }

        // ===== 2FA por mail (solo si aplica al usuario) =====
        $graceWarning = null;
        if ($this->twoFactor->isEnforcedFor($user)) {
            if (!$user->hasVerifiedEmail()) {
                if ($this->twoFactor->inGrace()) {
                    $left = $this->twoFactor->graceDaysLeft();
                    $graceWarning = [
                        'days_left' => $left,
                        'message' => null === $left
                            ? 'Protegé tu cuenta: cargá tu mail en tu perfil.'
                            : sprintf('Protegé tu cuenta: te quedan %d días para cargar tu mail.', $left),
                    ];
                } else {
                    return $this->json([
                        'success' => false,
                        'error' => 'Protegé tu cuenta: cargá tu mail para seguir entrando.',
                        'error_code' => 'enrollment_required',
                    ]);
                }
            } else {
                [$challenge] = $this->twoFactor->issue($user, \App\Entity\TwoFactorChallenge::PURPOSE_LOGIN);
                if (null === $challenge) {
                    return $this->jsonError('No se pudo enviar el código', Response::HTTP_INTERNAL_SERVER_ERROR, 'mail_failed');
                }

                return $this->json([
                    'success' => false,
                    'error' => 'Te enviamos un código de 6 dígitos a tu mail.',
                    'error_code' => 'two_factor_required',
                    'challenge_id' => $challenge->getId(),
                    'masked_email' => $this->twoFactor->maskedEmail($user->getEmail()),
                    'expires_in' => \App\Entity\TwoFactorChallenge::TTL_SECONDS,
                ]);
            }
        }

        $user->setLastLogin(new \DateTimeImmutable());
        $userRepository->getEntityManager()->flush();
        // El reset del rate-limit lo hace CodeAuthenticator::onAuthenticationSuccess()
        // (corre antes de llegar al controller).

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        // Only save session if it has been started (skip in stateless contexts like JWT-only APIs and tests)
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        // ===== TNSVT Reino v2: emit JWT + refresh token additively =====
        // Frontend can use either:
        // - session (PHP cookie, web UI)
        // - Authorization: Bearer JWT (mobile/APK)
        // - X-Game-Code (legacy header)
        $payload = $this->jwtService->buildLoginResponse($user);
        $payload['refresh_token'] = $this->refreshTokenService->issue($user);
        if (null !== $graceWarning) {
            $payload['enrollment_warning'] = $graceWarning;
        }

        $response = $this->json($payload);
        // Tras un login exitoso reseteamos el rate-limit (ver linea 110),
        // pero dejamos los headers visibles para que el cliente sepa que ya no
        // hay throttling pendiente.
        return $this->withRateLimitHeaders($response, self::LOGIN_RATE_LIMIT_MAX, $resetAt);
    }

    #[Route('/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/refresh',
        summary: 'Renovar token',
        description: 'Rota el refresh token y devuelve un nuevo access token + refresh token.',
        tags: ['Autenticación'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'ABCD01'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tokens renovados'),
            new OA\Response(response: 400, description: 'Campos faltantes'),
            new OA\Response(response: 401, description: 'Refresh token inválido o expirado'),
        ],
    )]
    public function refresh(
        Request $request,
        UserRepository $userRepository,
    ): JsonResponse|Response {
        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim($data['code'] ?? ''));
        $refreshToken = trim($data['refresh_token'] ?? '');

        if (empty($code) || empty($refreshToken)) {
            return $this->jsonError('Código y refresh_token requeridos', Response::HTTP_BAD_REQUEST, 'missing_fields');
        }

        $result = $this->refreshTokenService->rotateByCode($code, $refreshToken);

        if (!$result) {
            return $this->jsonError('Refresh token inválido o expirado', Response::HTTP_UNAUTHORIZED, 'invalid_refresh_token');
        }

        return $this->json([
            'success' => true,
            'token' => $result['token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in' => $result['expires_in'],
        ]);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST', 'GET'])]
    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Cerrar sesión',
        description: 'Invalida la sesión PHP y limpia el token storage. Acepta POST y GET para compatibilidad.',
        tags: ['Autenticación'],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada'),
        ],
    )]
    public function logout(
        Request $request,
        TokenStorageInterface $tokenStorage,
    ): JsonResponse {
        // Clear PHP session
        $session = $request->getSession();
        $session->invalidate();

        // Clear token storage
        $tokenStorage->setToken(null);

        return $this->json(['success' => true, 'message' => 'Sesión cerrada']);
    }

    #[Route('/check', name: 'api_auth_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/auth/check',
        summary: 'Verificar sesión',
        description: 'Devuelve el estado de autenticación del usuario actual. Requiere JWT Bearer.',
        tags: ['Autenticación'],
        responses: [
            new OA\Response(response: 200, description: 'Estado de autenticación'),
        ],
    )]
    public function check(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['authenticated' => false]);
        }

        return $this->json([
            'authenticated' => true,
            'user' => [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
                'email_masked' => $this->twoFactor->maskedEmail($user->getEmail()),
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->isTwoFactorEnabled(),
                'two_factor_required' => $this->twoFactor->isEnforcedFor($user) && !$user->hasVerifiedEmail() && !$this->twoFactor->inGrace(),
                'enrollment_warning_days' => $this->twoFactor->isEnforcedFor($user) && !$user->hasVerifiedEmail()
                    ? $this->twoFactor->graceDaysLeft()
                    : null,
            ],
        ]);
    }
}
