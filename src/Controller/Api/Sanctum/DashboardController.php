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
 *
 * Schema notes:
 * - tasks table has NO 'status' column (only 'active' boolean)
 * - economic_reminders has 'event_importance' (int 1-3) not 'impact' (string)
 * - economic_reminders has 'event_date' (varchar) + 'event_time' (varchar)
 * - tournament_trades has 'pnl_usd' (DECIMAL)
 * - admin_audit_log has 'action' (string) + 'result' (string)
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

        // KPI 1: Global PnL (sum of tournament_trades.pnl_usd where status='resolved')
        $globalPnlRow = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(pnl_usd), 0) AS total FROM tournament_trades WHERE status = 'resolved'"
        );
        $globalPnl = (float)($globalPnlRow['total'] ?? 0);

        // KPI 2: Active Seekers (users with last_activity_at < 2 min ago)
        $activeSeekers = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM users WHERE last_activity_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND active = 1"
        );

        // Total users
        $totalUsers = (int)$conn->fetchOne('SELECT COUNT(*) FROM users');
        $activeUsers = (int)$conn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');

        // KPI 3: Server Sanctum (proxy: count successful logins last 24h)
        $loginOk = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM admin_audit_log WHERE action = 'admin.login.success' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $loginTotal = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM admin_audit_log WHERE action LIKE 'admin.login%' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $serverHealthPct = $loginTotal > 0 ? round(($loginOk / max($loginTotal, 1)) * 100, 1) : 99.9;

        // KPI 4: Macro Signals (high-importance events in next 24h)
        // event_importance: 1=low, 2=medium, 3=high
        $macroSignals = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM economic_reminders WHERE remind_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) AND event_importance >= 3"
        );

        // Task Sovereignty (tasks table — uses 'active' column not 'status')
        $tasksActive = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 1");
        $tasksInactive = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 0");

        // Recent Signals (last 5 admin_audit_log entries)
        $recentSignals = $conn->fetchAllAssociative(
            "SELECT id, action, admin_code, created_at FROM admin_audit_log ORDER BY id DESC LIMIT 5"
        );

        return $this->json([
            'success' => true,
            'kpis' => [
                'globalPnl' => $globalPnl,
                'activeSeekers' => $activeSeekers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'serverSanctum' => $serverHealthPct,
                'macroSignals' => $macroSignals,
            ],
            'tasks' => [
                'active' => $tasksActive,
                'inactive' => $tasksInactive,
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