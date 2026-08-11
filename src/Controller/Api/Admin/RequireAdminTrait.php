<?php

namespace App\Controller\Api\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Trait para que los controllers /api/admin/* exijan ROLE_ADMIN
 * manualmente. Resuelve el problema de la firewall de Symfony 8
 * con lazy: true que ignora tokens seteados via subscriber
 * buscando el user via X-User-Code en el request.
 */
trait RequireAdminTrait
{
    protected function requireAdmin(?UserRepository $userRepository = null, ?TokenStorageInterface $tokenStorage = null): null|JsonResponse
    {
        // 1) Si la firewall ya cargo un user, usarlo
        $user = $this->getUser();
        if ($user instanceof User) {
            if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return $this->json(['error' => 'Se requiere rol de administrador'], Response::HTTP_FORBIDDEN);
            }
            return null;
        }

        // 2) Fallback: leer del header X-User-Code
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $code = $request?->headers->get('X-User-Code') ?? $request?->query->get('user_code');
        if (!$code) {
            return $this->json(['error' => 'Se requiere autenticación'], Response::HTTP_UNAUTHORIZED);
        }

        $userRepository ??= $this->container->get(UserRepository::class);
        $user = $userRepository->findByCode(strtoupper(trim((string) $code)));
        if (!$user || !$user->isActive()) {
            return $this->json(['error' => 'Usuario inválido o inactivo'], Response::HTTP_UNAUTHORIZED);
        }
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['error' => 'Se requiere rol de administrador'], Response::HTTP_FORBIDDEN);
        }

        // 3) Setear el token para que el resto del controller pueda usar $this->getUser()
        if ($tokenStorage === null) {
            try {
                $tokenStorage = $this->container->get(TokenStorageInterface::class);
            } catch (\Throwable) {
                $tokenStorage = null;
            }
        }
        if ($tokenStorage) {
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $tokenStorage->setToken($token);
        }

        return null;
    }
}
