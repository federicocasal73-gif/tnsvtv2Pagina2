<?php

namespace App\Controller\Api\Sanctum;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Audit Log API — Phase 1a.
 * Returns admin_audit_log entries with pagination + filters.
 * Admin-only: only ROLE_ADMIN can read admin action history.
 */
#[Route('/sanctum/api/audit', name: 'sanctum_api_audit_')]
#[IsGranted('ROLE_ADMIN')]
class AuditController extends AbstractController
{
    public function __construct(
        private Connection $db,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        \Symfony\Component\HttpFoundation\Request $request,
    ): JsonResponse {
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = min(100, max(10, (int)$request->query->get('per_page', 25)));
        $offset = ($page - 1) * $perPage;

        $action = $request->query->get('action');
        $result = $request->query->get('result');
        $adminCode = $request->query->get('admin');

        $where = [];
        $params = [];
        if ($action) { $where[] = 'action LIKE ?'; $params[] = '%' . $action . '%'; }
        if ($result) { $where[] = 'result = ?'; $params[] = $result; }
        if ($adminCode) { $where[] = 'admin_code = ?'; $params[] = $adminCode; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM admin_audit_log $whereSql", $params);

        $rows = $this->db->fetchAllAssociative(
            "SELECT id, admin_code, action, result, ip, user_agent, payload, created_at
             FROM admin_audit_log $whereSql
             ORDER BY id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        return $this->json([
            'success' => true,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => ceil($total / $perPage),
            ],
            'entries' => array_map(fn($r) => [
                'id' => (int)$r['id'],
                'admin' => $r['admin_code'],
                'action' => $r['action'],
                'result' => $r['result'],
                'ip' => $r['ip'],
                'userAgent' => $r['user_agent'],
                'payload' => $r['payload'] ? json_decode($r['payload'], true) : null,
                'createdAt' => $r['created_at'],
            ], $rows),
        ]);
    }
}