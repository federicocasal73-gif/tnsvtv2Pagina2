<?php

namespace App\Security;

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
     * Verifica el header X-Admin-Password.
     * Lanza 401 si no coincide.
     *
     * Para tener rate limit + audit log, usar AdminAuthService::verify() en su lugar.
     */
    protected function requireAdmin(Request $request): void
    {
        $provided = $request->headers->get('X-Admin-Password', '');
        if (empty($provided) || !hash_equals($this->getAdminPassword(), $provided)) {
            throw new AccessDeniedHttpException('Acceso denegado');
        }
    }
}