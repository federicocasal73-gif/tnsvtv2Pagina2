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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use App\Controller\Api\Admin\RequireAdminTrait;

#[Route('/api/admin/users')]
class UserController extends AbstractController
{
    use RequireAdminTrait;

    public function __construct(
        private UserRepository $userRepository,
        private TokenStorageInterface $tokenStorage,
    ) {}

    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(UserRepository $userRepository): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) {
            return $denied;
        }

        $users = $userRepository->findAll();
        $data = array_map(fn(User $u) => [
            'id' => $u->getId(),
            'code' => $u->getCode(),
            'name' => $u->getName(),
            'active' => $u->isActive(),
            'isAdmin' => in_array('ROLE_ADMIN', $u->getRoles(), true),
            'lastLogin' => $u->getLastLogin()?->format('Y-m-d H:i:s'),
        ], $users);

        usort($data, fn($a, $b) => $a['id'] <=> $b['id']);

        return $this->json($data);
    }

    #[Route('/{id}', name: 'api_admin_users_get', methods: ['GET'])]
    public function get(User $user): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) {
            return $denied;
        }

        return $this->json([
            'id' => $user->getId(),
            'code' => $user->getCode(),
            'name' => $user->getName(),
            'active' => $user->isActive(),
            'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
            'lastLogin' => $user->getLastLogin()?->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('', name: 'api_admin_users_create', methods: ['POST'])]
    public function create(Request $request, UserRepository $userRepository, EntityManagerInterface $em): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) {
            return $denied;
        }

        $data = json_decode($request->getContent(), true);
        $code = strtoupper(trim($data['code'] ?? ''));
        $name = trim($data['name'] ?? '');

        if (empty($code) || empty($name)) {
            return $this->json(['error' => 'Código y nombre son requeridos'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $userRepository->findOneBy(['code' => $code]);
        if ($existing) {
            return $this->json(['error' => sprintf('El código "%s" ya existe', $code)], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setCode($code);
        $user->setName($name);
        $user->setRoles(['ROLE_USER']);

        $em->persist($user);
        $em->flush();

        return $this->json([
            'id' => $user->getId(),
            'code' => $user->getCode(),
            'name' => $user->getName(),
            'active' => $user->isActive(),
            'isAdmin' => false,
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_users_update', methods: ['PUT'])]
    public function update(Request $request, User $user, EntityManagerInterface $em): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) {
            return $denied;
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $user->setName(trim($data['name']));
        }
        if (isset($data['code'])) {
            $user->setCode(strtoupper(trim($data['code'])));
        }
        if (isset($data['active'])) {
            $user->setActive((bool)$data['active']);
        }

        $em->flush();

        return $this->json([
            'id' => $user->getId(),
            'code' => $user->getCode(),
            'name' => $user->getName(),
            'active' => $user->isActive(),
            'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'api_admin_users_toggle', methods: ['PUT'])]
    public function toggleActive(User $user, EntityManagerInterface $em): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) {
            return $denied;
        }

        $user->setActive(!$user->isActive());
        $em->flush();

        return $this->json([
            'id' => $user->getId(),
            'active' => $user->isActive(),
        ]);
    }

    /**
     * Elimina un usuario del sistema.
     *
     * Validaciones de seguridad:
     * - No se puede eliminar a sí mismo
     * - No se puede eliminar al último admin (siempre debe quedar al menos 1)
     * - Limpia notifications, devices, conversation_participants y mensajes del usuario
     */
    #[Route('/{id}', name: 'api_admin_users_delete', methods: ['DELETE'])]
    public function delete(User $user, EntityManagerInterface $em, UserRepository $userRepository): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) {
            return $denied;
        }

        // 1) No permitir auto-eliminacion
        $currentUser = $this->tokenStorage->getToken()?->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            return $this->json([
                'error' => 'No podés eliminarte a vos mismo'
            ], Response::HTTP_FORBIDDEN);
        }

        // 2) Si es admin, verificar que no sea el último
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $admins = array_filter(
                $userRepository->findAll(),
                fn(User $u) => in_array('ROLE_ADMIN', $u->getRoles(), true) && $u->isActive()
            );
            if (count($admins) <= 1) {
                return $this->json([
                    'error' => 'No se puede eliminar al último administrador del sistema'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $code = $user->getCode();
        $name = $user->getName();
        $userId = $user->getId();

        // 3) Limpiar dependencias manualmente (porque User no tiene cascade)
        try {
            // Eliminar devices (FCM tokens) del usuario
            $em->getConnection()->executeStatement(
                'DELETE FROM devices WHERE user_id = ?',
                [$userId]
            );
            // Eliminar notifications del usuario
            $em->getConnection()->executeStatement(
                'DELETE FROM notifications WHERE user_id = ?',
                [$userId]
            );
            // Eliminar conversation_participants del usuario
            $em->getConnection()->executeStatement(
                'DELETE FROM conversation_participants WHERE user_id = ?',
                [$userId]
            );
            // Eliminar mensajes del usuario
            $em->getConnection()->executeStatement(
                'DELETE FROM messages WHERE sender_id = ?',
                [$userId]
            );
            // Eliminar likes del usuario
            $em->getConnection()->executeStatement(
                'DELETE FROM liked_posts WHERE user_code = ?',
                [$code]
            );
        } catch (\Throwable $e) {
            // Si falla la limpieza de dependencias, logueamos pero seguimos
            error_log('User delete cleanup error: ' . $e->getMessage());
        }

        // 4) Eliminar el usuario
        $em->remove($user);
        $em->flush();

        return $this->json([
            'success' => true,
            'deleted' => ['id' => $userId, 'code' => $code, 'name' => $name],
        ]);
    }
}