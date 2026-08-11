<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Servicio para verificar password admin con protecciones:
 * - Rate limit por IP (5 intentos / 15 min) via limiter.admin_login
 * - Audit log de cada intento (exitoso o fallido) via AdminAuditLogger
 *
 * Reemplaza al requireAdmin() del trait cuando se quiere proteccion completa.
 *
 * Uso en un controller:
 *   $this->adminAuth->verify($request, 'tournament.create');
 */
class AdminAuthService
{
    public function __construct(
        #[Autowire(service: 'limiter.admin_login')]
        private RateLimiterFactory $adminLoginLimiter,
        private AdminAuditLogger $auditLogger,
        private ?LoggerInterface $logger = null,
    ) {}

    public function verify(Request $request, string $actionName = 'unknown'): void
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $limiter = $this->adminLoginLimiter->create($ip);
        if (!$limiter->consume(1)->isAccepted()) {
            $this->auditLogger->log(AdminAuditLog::ACTION_LOGIN_FAIL, 'unknown', AdminAuditLog::RESULT_FAIL, ['ip' => $ip, 'action' => $actionName], 'Rate limit exceeded');
            if ($this->logger) {
                $this->logger->warning('Admin login rate limit exceeded', ['ip' => $ip, 'action' => $actionName]);
            }
            throw new TooManyRequestsHttpException(900, 'Demasiados intentos. Esperá 15 minutos.');
        }

        $provided = $request->headers->get('X-Admin-Password', '');
        if (empty($provided) || !hash_equals($this->getAdminPassword(), $provided)) {
            $this->auditLogger->logLoginAttempt('unknown', false, 'Wrong password for action: ' . $actionName);
            throw new AccessDeniedHttpException('Acceso denegado');
        }

        $this->auditLogger->logLoginAttempt('admin', true, 'Action: ' . $actionName);
    }

    private function getAdminPassword(): string
    {
        $pass = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? '';
        if (empty($pass)) {
            throw new \RuntimeException('ADMIN_PASSWORD no configurada. Definir en .env.local');
        }
        return $pass;
    }
}