<?php

namespace App\Controller\Api;

use App\Entity\TwoFactorChallenge;
use App\Entity\User;
use App\Repository\TwoFactorChallengeRepository;
use App\Repository\UserRepository;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private TwoFactorService $twoFactor,
        private TwoFactorChallengeRepository $challenges,
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/{code}', name: 'api_profile_show', methods: ['GET'])]
    public function show(string $code): JsonResponse
    {
        $user = $this->userRepository->findByCode($code);
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Usuario no encontrado'], 404);
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        $isOwner = $currentUser && $currentUser->getCode() === $code;

        return $this->json([
            'success' => true,
            'user' => [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'is_admin' => $user->getIsAdmin(),
                'tier' => $user->getTier(),
                'avatar_url' => $user->getAvatarUrl(),
                'notification_sound' => $user->getNotificationSound() ?? 'chime',
                'theme_preference' => $user->getThemePreference() ?? 'auto',
                'reputation' => $user->getReputation(),
                'coins' => $user->getCoins(),
                'wallet_balance' => $user->getWalletBalance(),
                'last_login' => $user->getLastLogin()?->format('Y-m-d H:i'),
                'vip_until' => $user->getVipUntil()?->format('Y-m-d'),
            ],
            'is_owner' => $isOwner,
        ]);
    }

    #[Route('', name: 'api_profile_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['name'])) {
            $user->setName(trim($data['name']));
        }
        if (isset($data['notification_sound'])) {
            $user->setNotificationSound($data['notification_sound']);
        }
        if (isset($data['theme_preference'])) {
            $user->setThemePreference($data['theme_preference']);
        }

        $this->em->flush();

        return $this->json(['success' => true, 'user' => [
            'code' => $user->getCode(),
            'name' => $user->getName(),
            'notification_sound' => $user->getNotificationSound(),
            'theme_preference' => $user->getThemePreference(),
        ]]);
    }

    #[Route('/email', name: 'api_profile_email_set', methods: ['POST'])]
    public function setEmail(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Mail inválido'], 400);
        }

        $taken = $this->userRepository->findOneBy(['email' => $email]);
        if ($taken instanceof User && $taken->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Ese mail ya está en uso'], 409);
        }

        $user->setEmail($email);
        $user->setEmailVerifiedAt(null);
        [$challenge] = $this->twoFactor->issue($user, TwoFactorChallenge::PURPOSE_ENROLL, $email);
        if (null === $challenge) {
            return $this->json(['success' => false, 'error' => 'No se pudo enviar el mail'], 500);
        }

        return $this->json([
            'success' => true,
            'challenge_id' => $challenge->getId(),
            'masked_email' => $this->twoFactor->maskedEmail($email),
            'expires_in' => TwoFactorChallenge::TTL_SECONDS,
        ]);
    }

    #[Route('/email/verify', name: 'api_profile_email_verify', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $challenge = $this->challenges->find((int) ($data['challenge_id'] ?? 0));
        if (!$challenge instanceof TwoFactorChallenge
            || $challenge->getUser()?->getId() !== $user->getId()
            || TwoFactorChallenge::PURPOSE_ENROLL !== $challenge->getPurpose()
            || !$challenge->isUsable()
        ) {
            return $this->json([
                'success' => false,
                'error' => 'El código venció o ya fue usado. Pedí uno nuevo.',
                'error_code' => 'challenge_expired',
            ], 410);
        }

        if (!$this->twoFactor->verify($challenge, (string) ($data['code'] ?? ''))) {
            $left = TwoFactorChallenge::MAX_ATTEMPTS - $challenge->getAttempts();

            return $this->json([
                'success' => false,
                'error' => 'Código incorrecto.',
                'attempts_left' => max(0, $left),
            ], 401);
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        if ('mandatory' === $this->twoFactor->mode()) {
            $user->setTwoFactorEnabled(true);
        }
        $this->em->flush();

        return $this->json([
            'success' => true,
            'masked_email' => $this->twoFactor->maskedEmail($user->getEmail()),
            'two_factor_enabled' => $user->isTwoFactorEnabled(),
        ]);
    }

    #[Route('/password', name: 'api_profile_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $new = (string) ($data['new_password'] ?? '');
        if (strlen($new) < TwoFactorService::PASSWORD_MIN_LENGTH) {
            return $this->json([
                'success' => false,
                'error' => 'La contraseña debe tener al menos ' . TwoFactorService::PASSWORD_MIN_LENGTH . ' caracteres',
            ], 400);
        }

        // Si ya tiene contraseña, exigir la actual (la sesión sola no basta).
        if (null !== $user->getPassword() && '' !== $user->getPassword()) {
            $current = (string) ($data['current_password'] ?? '');
            if ('' === $current || !$this->hasher->isPasswordValid($user, $current)) {
                return $this->json(['success' => false, 'error' => 'Tu contraseña actual no coincide'], 401);
            }
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $this->em->flush();

        return $this->json(['success' => true, 'message' => 'Contraseña actualizada.']);
    }

    #[Route('/avatar', name: 'api_profile_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $file = $request->files->get('avatar');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], 400);
        }

        // Validate
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowed)) {
            return $this->json(['success' => false, 'error' => 'Invalid file type'], 400);
        }
        if ($file->getSize() > 5_000_000) {
            return $this->json(['success' => false, 'error' => 'File too large (max 5MB)'], 400);
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = $user->getCode() . '.' . $file->guessExtension();
        $file->move($uploadDir, $filename);

        return $this->json(['success' => true, 'avatar_url' => '/uploads/avatars/' . $filename]);
    }

    #[Route('/avatar', name: 'api_profile_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $path = "$uploadDir/{$user->getCode()}.$ext";
            if (is_file($path)) {
                unlink($path);
                break;
            }
        }

        return $this->json(['success' => true]);
    }
}
