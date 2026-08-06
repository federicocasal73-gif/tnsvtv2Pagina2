<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Servicio centralizado para registrar acciones admin.
 * Usado en endpoints sensibles: requireAdmin(), tournament create/close/cancel,
 * admin login attempts.
 */
class AdminAuditLogger
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
    ) {}

    public function log(string $action, string $adminCode, string $result = AdminAuditLog::RESULT_SUCCESS, ?array $payload = null, ?string $notes = null): void
    {
        $log = new AdminAuditLog();
        $log->setAction($action);
        $log->setAdminCode($adminCode);
        $log->setResult($result);
        $log->setPayload($payload);
        $log->setNotes($notes);

        $req = $this->requestStack->getCurrentRequest();
        if ($req instanceof Request) {
            $log->setIp($req->getClientIp() ?? '');
            $log->setUserAgent(substr($req->headers->get('User-Agent', ''), 0, 255));
        }

        $this->em->persist($log);
        $this->em->flush();
    }

    public function logLoginAttempt(string $adminCode, bool $success, ?string $notes = null): void
    {
        $this->log(
            $success ? AdminAuditLog::ACTION_LOGIN_SUCCESS : AdminAuditLog::ACTION_LOGIN_FAIL,
            $adminCode,
            $success ? AdminAuditLog::RESULT_SUCCESS : AdminAuditLog::RESULT_FAIL,
            null,
            $notes
        );
    }
}