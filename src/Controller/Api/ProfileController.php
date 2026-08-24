<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
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

        $filename = 'avatar_' . $user->getCode() . '_' . time() . '.' . $file->guessExtension();
        $file->move($uploadDir, $filename);

        $user->setAvatarUrl('/uploads/avatars/' . $filename);
        $this->em->flush();

        return $this->json(['success' => true, 'avatar_url' => $user->getAvatarUrl()]);
    }

    #[Route('/avatar', name: 'api_profile_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $user->setAvatarUrl(null);
        $this->em->flush();

        return $this->json(['success' => true]);
    }
}
