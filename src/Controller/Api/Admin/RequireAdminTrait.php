<?php

namespace App\Controller\Api\Admin;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trait para que los controllers /api/admin/* exijan ROLE_ADMIN.
 *
 * Ahora confía solo en el firewall (JWT/Bearer), sin fallback a X-User-Code
 * (ese header era un secreto-de-usuario público que permitía escalada de
 * privilegios sin autenticación real).
 */
trait RequireAdminTrait
{
    protected function requireAdmin(): null|JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['error' => 'Se requiere autenticación'], Response::HTTP_UNAUTHORIZED);
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['error' => 'Se requiere rol de administrador'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
