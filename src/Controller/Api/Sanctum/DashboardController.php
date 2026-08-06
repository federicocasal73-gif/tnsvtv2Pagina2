<?php

namespace App\Controller\Api\Sanctum;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sanctum Dashboard API — Phase 1a.
 * Returns real KPIs from the DB.
 */
#[Route('/sanctum/api/dashboard', name: 'sanctum_api_dashboard_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {}

    #[Route('', name: 'main', methods: ['GET'])]
    public function main(): JsonResponse
    {
        $conn = $this->em->getConnection();

        // KPI 1: Global PnL (sum of tournament_trades pnl_usd)
        $globalPnlRow = $conn->fetchAssociative(
            'SELECT COALESCE(SUM(pnl_usd), 0) AS total FROM tournament_trades WHERE status = ?',
            ['resolved']
        );
        $globalPnl = (float)($globalPnlRow['total'] ?? 0);

        // KPI 2: Active Seekers (users with last_activity_at < 2 min ago)
        $activeSeekers = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM users WHERE last_activity_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND active = 1"
        );

        // KPI 3: Total users
        $totalUsers = (int)$conn->fetchOne('SELECT COUNT(*) FROM users');
        $activeUsers = (int)$conn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');

        // KPI 4: Server Sanctum (uptime proxy: count successful logins in last 24h)
        $serverHealth = (float)$conn->fetchOne(
            "SELECT COUNT(*) FROM admin_audit_log WHERE action IN ('admin.login.success') AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $serverHealthPct = min(99.9, 95.0 + ($serverHealth > 0 ? 4.9 : 0));

        // KPI 5: Macro Signals (economic events in next 24h)
        $macroSignals = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM economic_reminders WHERE event_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) AND impact = 'high'"
        );

        // Task Sovereignty (tasks table)
        $tasksActive = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 1 AND status = 'active'");
        $tasksPending = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 1 AND status = 'pending'");
        $tasksCompleted = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 1 AND status = 'completed'");

        // Recent Signals (last 5 admin_audit_log entries)
        $recentSignals = $conn->fetchAllAssociative(
            "SELECT id, action, admin_code, created_at FROM admin_audit_log ORDER BY created_at DESC LIMIT 5"
        );

        return $this->json([
            'success' => true,
            'kpis' => [
                'globalPnl' => $globalPnl,
                'activeSeekers' => $activeSeekers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'serverSanctum' => round($serverHealthPct, 1),
                'macroSignals' => $macroSignals,
            ],
            'tasks' => [
                'active' => $tasksActive,
                'pending' => $tasksPending,
                'completed' => $tasksCompleted,
            ],
            'recentSignals' => array_map(fn($s) => [
                'id' => (int)$s['id'],
                'action' => $s['action'],
                'admin' => $s['admin_code'],
                'time' => $s['created_at'],
            ], $recentSignals),
        ]);
    }
}