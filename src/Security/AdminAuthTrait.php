<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Helper para endpoints admin.
 * El admin se autentica con el header X-Admin-Password.
 * El mismo password se usa en /admin/login del front.
 *
 * La password se lee de la variable de entorno ADMIN_PASSWORD.
 * Si no esta definida, se usa un valor default seguro (solo desarrollo).
 * En produccion, definir ADMIN_PASSWORD en .env.local (gitignored).
 *
 * A2 - Rate limit + audit log se aplican via AdminAuthService en el caller.
 * Este trait queda minimal para mantener compatibilidad con todos los controllers.
 */
trait AdminAuthTrait
{
    /**
     * Obtiene la password admin desde variable de entorno.
     * Debe estar definida en .env.local en produccion.
     */
    private function getAdminPassword(): string
    {
        $pass = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? '';
        if (empty($pass)) {
            throw new \RuntimeException('ADMIN_PASSWORD no configurada. Definir en .env.local');
        }
        return $pass;
    }

    /**
     * Verifica acceso admin por dos vías (OR):
     *   1. Sesión/JWT autenticada de un usuario con getIsAdmin() true
     *      (el admin ya logueó con password vía /api/auth/login; el
     *      navegador manda la cookie de sesión en same-origin fetch).
     *   2. Header X-Admin-Password igual al secreto ADMIN_PASSWORD
     *      (para scripts, jobs y clientes sin sesión).
     *
     * Lanza 403 si ninguna vía valida.
     *
     * Para tener rate limit + audit log, usar AdminAuthService::verify() en su lugar.
     */
    protected function requireAdmin(Request $request): void
    {
        // $this is always an AbstractController subclass (all 6 users of
        // this trait extend it), so getUser() exists.
        $user = $this->getUser();
        if ($user instanceof User && $user->getIsAdmin()) {
            return;
        }
        $provided = $request->headers->get('X-Admin-Password', '');
        if (empty($provided) || !hash_equals($this->getAdminPassword(), $provided)) {
            throw new AccessDeniedHttpException('Acceso denegado');
        }
    }
}