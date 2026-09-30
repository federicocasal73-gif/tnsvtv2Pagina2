<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\TwoFactorChallenge;
use App\Entity\User;
use App\Repository\TwoFactorChallengeRepository;
use App\Repository\UserRepository;
use App\Service\AppMailer;
use App\Service\Auth\JwtService;
use App\Service\Auth\RefreshTokenService;
use App\Service\RateLimiterService;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Segundo factor por mail + recupero de contraseña.
 *
 * - verify/resend: completan el login pendiente (emiten sesión+JWT igual
 *   que /api/auth/login) o el enrolamiento.
 * - forgot/reset: recupero sin login; respuesta genérica para no enumerar.
 * - enroll/start: pide el código al mail que el usuario declara.
 */
#[Route('/api/auth')]
class TwoFactorController extends AbstractController
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private TwoFactorChallengeRepository $challenges,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private JwtService $jwtService,
        private RefreshTokenService $refreshTokenService,
        private UserPasswordHasherInterface $hasher,
        private TokenStorageInterface $tokenStorage,
        private RateLimiterService $rateLimiter,
        private AppMailer $mailer,
    ) {
    }

    #[Route('/2fa/verify', name: 'api_auth_2fa_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $challenge = $this->challenges->find((int) ($data['challenge_id'] ?? 0));
        if (!$challenge instanceof TwoFactorChallenge || !$challenge->isUsable()) {
            return $this->json([
                'success' => false,
                'error' => 'El código venció o ya fue usado. Pedí uno nuevo.',
                'error_code' => 'challenge_expired',
            ], Response::HTTP_GONE);
        }

        if (!$this->twoFactor->verify($challenge, (string) ($data['code'] ?? ''))) {
            $left = TwoFactorChallenge::MAX_ATTEMPTS - $challenge->getAttempts();

            return $this->json([
                'success' => false,
                'error' => $left > 0
                    ? sprintf('Código incorrecto. Te quedan %d intentos.', $left)
                    : 'Demasiados intentos. Pedí un código nuevo.',
                'error_code' => 'code_invalid',
                'attempts_left' => max(0, $left),
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $challenge->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return $this->json(['success' => false, 'error' => 'Usuario inválido'], Response::HTTP_UNAUTHORIZED);
        }

        if (TwoFactorChallenge::PURPOSE_ENROLL === $challenge->getPurpose()) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            if ('mandatory' === $this->twoFactor->mode()) {
                $user->setTwoFactorEnabled(true);
            }
            $this->em->flush();
        }

        // El verify completa el login pendiente: misma respuesta que login.
        $this->resetLoginLimit($request, $user);

        return $this->json($this->issueLogin($request, $user) + ['enrolled' => TwoFactorChallenge::PURPOSE_ENROLL === $challenge->getPurpose()]);
    }

    #[Route('/2fa/resend', name: 'api_auth_2fa_resend', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $challenge = $this->challenges->find((int) ($data['challenge_id'] ?? 0));
        if (!$challenge instanceof TwoFactorChallenge || !$challenge->isUsable()) {
            return $this->json([
                'success' => false,
                'error' => 'El código venció. Iniciá sesión de nuevo para pedir otro.',
                'error_code' => 'challenge_expired',
            ], Response::HTTP_GONE);
        }

        $user = $challenge->getUser();
        $email = $user?->getEmail();
        if (null === $email || '' === trim($email)) {
            return $this->json(['success' => false, 'error' => 'Sin mail asociado'], Response::HTTP_BAD_REQUEST);
        }

        if (null === $this->twoFactor->resend($challenge, $email)) {
            return $this->json([
                'success' => false,
                'error' => 'Esperá unos segundos antes de reenviar.',
                'error_code' => 'resend_cooldown',
                'retry_after' => $this->twoFactor->resendRetryAfter($challenge),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->json([
            'success' => true,
            'challenge_id' => $challenge->getId(),
            'masked_email' => $this->twoFactor->maskedEmail($email),
            'expires_in' => TwoFactorChallenge::TTL_SECONDS,
        ]);
    }

    #[Route('/2fa/enroll/start', name: 'api_auth_2fa_enroll_start', methods: ['POST'])]
    public function enrollStart(Request $request): JsonResponse
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        if ($this->rateLimiter->checkAndHit('2fa_enroll:' . $ip, 5, 3600) <= 0) {
            return $this->json(['success' => false, 'error' => 'Demasiados intentos. Probá en una hora.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ('' === $code || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Código y mail válido son requeridos'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->users->findByCode($code);
        if (!$user instanceof User || !$user->isActive()) {
            return $this->json(['success' => false, 'error' => 'Código inválido o desactivado'], Response::HTTP_UNAUTHORIZED);
        }

        $taken = $this->users->findOneBy(['email' => $email]);
        if ($taken instanceof User && $taken->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Ese mail ya está en uso'], Response::HTTP_CONFLICT);
        }

        $user->setEmail($email);
        [$challenge] = $this->twoFactor->issue($user, TwoFactorChallenge::PURPOSE_ENROLL, $email);
        if (null === $challenge) {
            return $this->json(['success' => false, 'error' => 'No se pudo enviar el mail'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'challenge_id' => $challenge->getId(),
            'masked_email' => $this->twoFactor->maskedEmail($email),
            'expires_in' => TwoFactorChallenge::TTL_SECONDS,
        ]);
    }

    #[Route('/password/forgot', name: 'api_auth_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        if ($this->rateLimiter->checkAndHit('pwd_forgot:' . $ip, 5, 3600) <= 0) {
            return $this->json(['success' => false, 'error' => 'Demasiados intentos. Probá en una hora.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Respuesta genérica SIEMPRE: no revelar si el código existe.
        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $user = '' !== $code ? $this->users->findByCode($code) : null;
        if ($user instanceof User && $user->isActive() && $user->hasVerifiedEmail()) {
            $this->twoFactor->issue($user, TwoFactorChallenge::PURPOSE_RESET);
        }

        return $this->json([
            'success' => true,
            'message' => 'Si el código existe y tiene mail verificado, enviamos un código de recuperación.',
        ]);
    }

    #[Route('/code/forgot', name: 'api_auth_code_forgot', methods: ['POST'])]
    public function codeForgot(Request $request): JsonResponse
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        if ($this->rateLimiter->checkAndHit('code_forgot:' . $ip, 5, 3600) <= 0) {
            return $this->json(['success' => false, 'error' => 'Demasiados intentos. Probá en una hora.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Respuesta genérica SIEMPRE: no revelar si el mail existe.
        $data = json_decode($request->getContent(), true);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ('' !== $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->users->findOneBy(['email' => $email]);
            if ($user instanceof User && $user->isActive() && $user->hasVerifiedEmail()) {
                $this->mailer->sendCode($email, (string) $user->getCode(), 'code');
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Si el mail existe y está verificado, enviamos tu código.',
        ]);
    }

    #[Route('/password/reset', name: 'api_auth_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $challenge = $this->challenges->find((int) ($data['challenge_id'] ?? 0));
        if (!$challenge instanceof TwoFactorChallenge
            || TwoFactorChallenge::PURPOSE_RESET !== $challenge->getPurpose()
            || !$challenge->isUsable()
        ) {
            return $this->json([
                'success' => false,
                'error' => 'El código venció o ya fue usado. Pedí uno nuevo.',
                'error_code' => 'challenge_expired',
            ], Response::HTTP_GONE);
        }

        $new = (string) ($data['new_password'] ?? '');
        if (strlen($new) < TwoFactorService::PASSWORD_MIN_LENGTH) {
            return $this->json([
                'success' => false,
                'error' => 'La contraseña debe tener al menos ' . TwoFactorService::PASSWORD_MIN_LENGTH . ' caracteres',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->twoFactor->verify($challenge, (string) ($data['code'] ?? ''))) {
            $left = TwoFactorChallenge::MAX_ATTEMPTS - $challenge->getAttempts();

            return $this->json([
                'success' => false,
                'error' => 'Código incorrecto.',
                'attempts_left' => max(0, $left),
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $challenge->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Usuario inválido'], Response::HTTP_UNAUTHORIZED);
        }
        $user->setPassword($this->hasher->hashPassword($user, $new));
        // Invalidar sesiones/refresh vigentes: el recupero roba el acceso.
        $user->setCurrentRefreshTokenHash(null);
        $user->setRefreshTokenRotatedAt(null);
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Contraseña actualizada. Iniciá sesión de nuevo.']);
    }

    #[Route('/password/reset-by-code', name: 'api_auth_password_reset_by_code', methods: ['POST'])]
    public function resetByCode(Request $request): JsonResponse
    {
        // Variante cómoda para el formulario web: el cliente no conoce el
        // challenge_id (forgot responde genérico a propósito). El server
        // resuelve el último challenge activo de recupero del usuario.
        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $user = '' !== $code ? $this->users->findByCode($code) : null;
        if (!$user instanceof User || !$user->isActive()) {
            return $this->json(['success' => false, 'error' => 'Código o código de mail inválido.'], Response::HTTP_UNAUTHORIZED);
        }
        $challenge = $this->challenges->findActiveForUser((int) $user->getId(), TwoFactorChallenge::PURPOSE_RESET);
        if (!$challenge instanceof TwoFactorChallenge) {
            return $this->json([
                'success' => false,
                'error' => 'No hay código vigente. Pedí uno nuevo.',
                'error_code' => 'challenge_expired',
            ], Response::HTTP_GONE);
        }

        $new = (string) ($data['new_password'] ?? '');
        if (strlen($new) < TwoFactorService::PASSWORD_MIN_LENGTH) {
            return $this->json([
                'success' => false,
                'error' => 'La contraseña debe tener al menos ' . TwoFactorService::PASSWORD_MIN_LENGTH . ' caracteres',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->twoFactor->verify($challenge, (string) ($data['email_code'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Código o código de mail inválido.'], Response::HTTP_UNAUTHORIZED);
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $user->setCurrentRefreshTokenHash(null);
        $user->setRefreshTokenRotatedAt(null);
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Contraseña actualizada. Iniciá sesión de nuevo.']);
    }

    /**
     * Emite sesión + JWT + refresh igual que AuthController::login().
     */
    private function issueLogin(Request $request, User $user): array
    {
        $user->setLastLogin(new \DateTimeImmutable());
        $this->em->flush();

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $payload = $this->jwtService->buildLoginResponse($user);
        $payload['refresh_token'] = $this->refreshTokenService->issue($user);

        return $payload;
    }

    private function resetLoginLimit(Request $request, User $user): void
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        $this->rateLimiter->reset('login_attempts:' . $ip . ':' . $user->getCode());
    }
}
