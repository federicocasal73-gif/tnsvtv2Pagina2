<?php

namespace App\Controller\Sanctum;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PurgeBlockedException;
use App\Service\UserPurgeService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * F7-fix — Re-added admin users endpoints.
 *
 * Originally referenced by route /sanctum/api/users (sanctum_api_users_list).
 * The original controller file went missing at some point, causing
 * 500 errors. This is a clean re-implementation that returns the data
 * the admin users.html.twig template expects.
 */
#[Route('/sanctum/api/users')]
class UsersController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {}

    private function requireAdmin(): ?JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)
            && !$user->getIsAdmin()) {
            return $this->json(['success' => false, 'error' => 'Forbidden'], 403);
        }
        return null;
    }

    #[Route('', name: 'sanctum_api_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) return $err;

        $users = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.code', 'ASC')
            ->getQuery()
            ->getResult();

        $items = array_map(static function (User $u) {
            return [
                'id'          => $u->getId(),
                'code'        => $u->getCode(),
                'name'        => $u->getName(),
                'tier'        => $u->getTier(),
                'roles'       => $u->getRoles(),
                'active'      => $u->isActive(),
                'email'       => $u->getEmail(),
                'last_login'  => $u->getLastLogin()?->format('c'),
            ];
        }, $users);

        return $this->json(['success' => true, 'users' => $items, 'count' => count($items)]);
    }

    #[Route('/{code}/tier', name: 'sanctum_api_users_update_tier', methods: ['PATCH'])]
    public function updateTier(string $code, Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) return $err;

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['tier'])) {
            return $this->json(['success' => false, 'error' => 'tier required'], 400);
        }

        $newTier = (string) $data['tier'];
        if (!in_array($newTier, User::TIERS, true)) {
            return $this->json(['success' => false, 'error' => 'Invalid tier'], 400);
        }

        $user = $this->userRepository->findOneBy(['code' => $code]);
        if (!$user) return $this->json(['success' => false, 'error' => 'Not found'], 404);

        $oldTier = $user->getTier();
        $user->setTier($newTier);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'user' => $code,
            'oldTier' => $oldTier,
            'newTier' => $user->getTier(),
            'tier' => $user->getTier(),
        ]);
    }

    #[Route('/{code}/active', name: 'sanctum_api_users_toggle_active', methods: ['PATCH'])]
    public function toggleActive(string $code, Request $request): JsonResponse
    {
        if ($err = $this->requireAdmin()) return $err;

        $user = $this->userRepository->findOneBy(['code' => $code]);
        if (!$user) return $this->json(['success' => false, 'error' => 'Not found'], 404);

        $data = json_decode($request->getContent(), true);
        $newState = isset($data['active']) ? (bool) $data['active'] : !$user->isActive();
        $user->setActive($newState);
        $this->em->flush();

        return $this->json(['success' => true, 'active' => $user->isActive()]);
    }

    #[Route('/{code}', name: 'sanctum_api_users_delete', methods: ['DELETE'])]
    public function delete(string $code, Request $request, UserPurgeService $purge): JsonResponse
    {
        if ($err = $this->requireAdmin()) return $err;

        $user = $this->userRepository->findOneBy(['code' => $code]);
        if (!$user) return $this->json(['success' => false, 'error' => 'Not found'], 404);

        // Never let an admin delete themselves (would lock them out).
        $me = $this->getUser();
        if ($me instanceof User && $me->getId() === $user->getId()) {
            return $this->json(['success' => false, 'error' => 'No podés eliminar tu propio usuario'], 400);
        }

        // ?force=1 → purgado total via UserPurgeService (transacción única
        // con rollback; bloqueos de negocio llegan como 409 explicados).
        if ($request->query->get('force')) {
            try {
                $deleted = $purge->purge($user);
            } catch (PurgeBlockedException $e) {
                return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
            }

            return $this->json(['success' => true, 'deleted' => $code, 'purged' => $deleted]);
        }

        // ~30 tables reference users. Fresh adepts (no activity) delete
        // cleanly; users with history hit FK constraints — catch that and
        // answer 409 suggesting deactivate instead of a raw 500.
        try {
            $this->em->remove($user);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            return $this->json([
                'success' => false,
                'error' => 'El adepto tiene datos asociados (trades, mensajes, etc.). Desactivalo en su lugar.',
            ], 409);
        }

        return $this->json(['success' => true, 'deleted' => $code]);
    }
}
