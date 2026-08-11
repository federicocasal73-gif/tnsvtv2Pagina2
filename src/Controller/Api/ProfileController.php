<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profile')]
class ProfileController extends AbstractController
{
    private string $avatarDir;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct(private UserRepository $userRepository)
    {
        $this->avatarDir = dirname(__DIR__, 3) . '/public/uploads/avatars';
    }

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    // Avatar routes MUST come before the variable {code} route

    #[Route('/avatar', name: 'api_profile_avatar_get', methods: ['GET'])]
    public function getAvatar(Request $request): JsonResponse
    {
        $userCode = strtoupper($request->query->get('user_code', ''));
        if (!$userCode) {
            return $this->json(['error' => 'Missing user_code'], 400);
        }
        $user = $this->userRepository->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $this->json([
            'avatar_url' => $this->getAvatarUrl($userCode),
            'avatar_color' => null,
            'initials' => strtoupper(substr(trim($user->getName() ?? $userCode), 0, 2)),
        ]);
    }

    #[Route('/avatar', name: 'api_profile_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request): JsonResponse
    {
        $authUser = $this->getCurrentUser($request);
        if (!$authUser) {
            return $this->json(['error' => 'No autorizado — X-Game-Code requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $userCode = strtoupper($request->query->get('user_code', ''));
        if (!$userCode) {
            return $this->json(['error' => 'Missing user_code'], 400);
        }
        if (strtoupper($authUser->getCode() ?? '') !== $userCode) {
            return $this->json(['error' => 'No autorizado — solo puedes modificar tu propio avatar'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $file = $request->files->get('avatar');
        if (!$file || !$file->isValid()) {
            return $this->json(['error' => 'No valid file uploaded. Max 5MB, formats: jpg/png/gif/webp'], 400);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => 'File too large. Max 5MB allowed.'], 413);
        }

        if (!is_dir($this->avatarDir)) {
            mkdir($this->avatarDir, 0775, true);
        }

        // Delete old avatar
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            $old = "$this->avatarDir/$userCode.$ext";
            if (is_file($old)) unlink($old);
        }

        $extension = strtolower($file->guessExtension() ?? 'png');
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'png';
        }

        $file->move($this->avatarDir, "$userCode.$extension");

        return $this->json([
            'avatar_url' => $this->getAvatarUrl($userCode),
            'avatar_color' => null,
        ]);
    }

    #[Route('/avatar', name: 'api_profile_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(Request $request): JsonResponse
    {
        $authUser = $this->getCurrentUser($request);
        if (!$authUser) {
            return $this->json(['error' => 'No autorizado — X-Game-Code requerido'], Response::HTTP_UNAUTHORIZED);
        }

        $userCode = strtoupper($request->query->get('user_code', ''));
        if (!$userCode) {
            return $this->json(['error' => 'Missing user_code'], 400);
        }
        if (strtoupper($authUser->getCode() ?? '') !== $userCode) {
            return $this->json(['error' => 'No autorizado — solo puedes eliminar tu propio avatar'], Response::HTTP_FORBIDDEN);
        }

        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            $path = "$this->avatarDir/$userCode.$ext";
            if (is_file($path)) unlink($path);
        }

        return $this->json(['success' => true]);
    }

    // Variable route MUST come last to avoid catching /avatar

    #[Route('/{code}', name: 'api_profile_show', methods: ['GET'])]
    public function show(string $code): JsonResponse
    {
        $user = $this->userRepository->findByCode(strtoupper($code));
        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $roles = $user->getRoles();
        $initials = strtoupper(substr(trim($user->getName() ?? $user->getCode() ?? '?'), 0, 2));

        return $this->json([
            'code' => $user->getCode(),
            'name' => $user->getName(),
            'role' => $roles[0] ?? 'ROLE_USER',
            'wallet' => $user->getWalletBalance(),
            'is_admin' => in_array('ROLE_ADMIN', $roles, true),
            'last_login' => $user->getLastLogin()?->format('c'),
            'avatar_url' => $this->getAvatarUrl($user->getCode()),
            'avatar_color' => null,
            'initials' => $initials,
        ]);
    }

    private function getAvatarUrl(?string $userCode): ?string
    {
        if (!$userCode) return null;
        foreach (self::ALLOWED_EXTENSIONS as $ext) {
            $path = "$this->avatarDir/$userCode.$ext";
            if (is_file($path)) {
                return "/uploads/avatars/$userCode.$ext";
            }
        }
        return null;
    }
}
