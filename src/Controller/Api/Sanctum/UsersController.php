<?php

namespace App\Controller\Api\Sanctum;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Users API — Phase 1a.
 * Lists users with filters (tier, active, code).
 * Admin-only: contains PII (email, walletBalance, coins, reputation).
 */
#[Route('/sanctum/api/users', name: 'sanctum_api_users_')]
#[IsGranted('ROLE_ADMIN')]
class UsersController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        $data = array_map(fn($u) => [
            'id' => $u->getId(),
            'code' => $u->getCode(),
            'name' => $u->getName(),
            'email' => $u->getEmail(),
            'tier' => $u->getTier(),
            'active' => $u->isActive(),
            'isAdmin' => $u->getIsAdmin(),
            'roles' => $u->getRoles(),
            'walletBalance' => $u->getWalletBalance(),
            'coins' => $u->getCoins(),
            'reputation' => $u->getReputation(),
            'lastLogin' => $u->getLastLogin()?->format('Y-m-d H:i'),
            'lastActivity' => $u->getLastActivityAt()?->format('Y-m-d H:i'),
        ], $users);

        return $this->json([
            'success' => true,
            'count' => count($data),
            'users' => $data,
        ]);
    }

    #[Route('/{code}/tier', name: 'update_tier', methods: ['PATCH'])]
    public function updateTier(string $code, \Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $newTier = $data['tier'] ?? null;

        if (!in_array($newTier, User::TIERS, true)) {
            return $this->json(['success' => false, 'error' => 'Invalid tier'], 400);
        }

        $user = $this->userRepository->findOneBy(['code' => $code]);
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'User not found'], 404);
        }

        $oldTier = $user->getTier();
        $user->setTier($newTier);
        $this->userRepository->getEntityManager()->flush();

        // Audit log
        $admin = $this->getUser();
        $this->userRepository->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO admin_audit_log (admin_code, action, result, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
            [
                $admin?->getCode() ?? 'unknown',
                'user.tier.change',
                'success',
                $request->getClientIp() ?? '0.0.0.0',
                substr($request->headers->get('User-Agent', ''), 0, 200),
            ]
        );

        return $this->json([
            'success' => true,
            'user' => $code,
            'oldTier' => $oldTier,
            'newTier' => $newTier,
        ]);
    }

    #[Route('/{code}/active', name: 'toggle_active', methods: ['PATCH'])]
    public function toggleActive(string $code, \Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $user = $this->userRepository->findOneBy(['code' => $code]);
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'User not found'], 404);
        }

        $user->setActive(!$user->isActive());
        $this->userRepository->getEntityManager()->flush();

        return $this->json([
            'success' => true,
            'user' => $code,
            'active' => $user->isActive(),
        ]);
    }
}