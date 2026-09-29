<?php

namespace App\Controller\Api\Sanctum;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Dashboard API — Phase 1a.
 * Returns real KPIs from the DB.
 * Admin-only: KPIs are global (total users, global PnL, server health,
 * recent admin actions) and would leak business intel to regular users.
 *
 * Schema notes:
 * - tasks table has NO 'status' column (only 'active' boolean)
 * - economic_reminders has 'event_importance' (int 1-3) not 'impact' (string)
 * - economic_reminders has 'event_date' (varchar) + 'event_time' (varchar)
 * - admin_audit_log has 'action' (string) + 'result' (string)
 * - globalPnl previously came from `tournament_trades` (subsystem deprecated
 *   in Version20260822000000). Now returns 0 + a warning so the endpoint
 *   stays 200 OK and admins see why the KPI is unavailable.
 *
 * Implementation note: queries use Doctrine ORM / DQL for cross-platform
 * portability (CI runs on sqlite; prod is MySQL). Native SQL functions
 * like `DATE_SUB(NOW(), INTERVAL 2 MINUTE)` were removed because they
 * don't exist in sqlite.
 */
#[Route('/sanctum/api/dashboard', name: 'sanctum_api_dashboard_')]
#[IsGranted('ROLE_ADMIN')]
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
        $now = new \DateTimeImmutable();

        // KPI 1: Global PnL — historically sourced from `tournament_trades`.
        // That table was dropped by Version20260822000000 when the tournaments
        // subsystem was deprecated. Returning 0 with a warning so the endpoint
        // stays 200 OK and the UI can show "N/D" instead of breaking.
        $globalPnl = 0.0;
        $globalPnlWarning = 'tournament_trades_subsystem_deprecated';
        $globalPnlWarningDetail = 'Reemplazo disponible en /api/oracle/global-stats (P1+).';

        // KPI 2: Active Seekers (users with last_activity_at within last 2 min)
        $activeSeekers = (int)$this->em->createQuery(
            'SELECT COUNT(u) FROM App\\Entity\\User u
             WHERE u.active = 1 AND u.lastActivityAt > :since'
        )->setParameter('since', $now->modify('-2 minutes'))->getSingleScalarResult();

        // Total / active users
        $totalUsers = (int)$conn->fetchOne('SELECT COUNT(*) FROM users');
        $activeUsers = (int)$conn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');

        // KPI 3: Server Sanctum (proxy: count successful logins last 24h)
        $since24h = $now->modify('-24 hours');
        $loginOk = (int)$this->em->createQuery(
            'SELECT COUNT(a) FROM App\\Entity\\AdminAuditLog a
             WHERE a.action = :ok AND a.createdAt > :since'
        )->setParameter('ok', 'admin.login.success')
         ->setParameter('since', $since24h)
         ->getSingleScalarResult();

        $loginTotal = (int)$this->em->createQuery(
            'SELECT COUNT(a) FROM App\\Entity\\AdminAuditLog a
             WHERE a.action LIKE :prefix AND a.createdAt > :since'
        )->setParameter('prefix', 'admin.login%')
         ->setParameter('since', $since24h)
         ->getSingleScalarResult();

        $serverHealthPct = $loginTotal > 0 ? round(($loginOk / max($loginTotal, 1)) * 100, 1) : 99.9;

        // KPI 4: Macro Signals (high-importance events in next 24h)
        $next24h = $now->modify('+24 hours');
        $macroSignals = (int)$this->em->createQuery(
            'SELECT COUNT(e) FROM App\\Entity\\EconomicReminder e
             WHERE e.remindAt BETWEEN :now AND :next AND e.eventImportance >= 3'
        )->setParameter('now', $now)
         ->setParameter('next', $next24h)
         ->getSingleScalarResult();

        // Task Sovereignty (post-migration: status-driven, active flag as filter)
        $tasksActive = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 1 AND status != 'approved'");
        $tasksInactive = (int)$conn->fetchOne("SELECT COUNT(*) FROM tasks WHERE active = 0 OR status = 'approved'");

        // Recent Signals (last 5 admin_audit_log entries)
        $recentSignals = $conn->fetchAllAssociative(
            'SELECT id, action, admin_code, created_at FROM admin_audit_log ORDER BY id DESC LIMIT 5'
        );

        return $this->json([
            'success' => true,
            'kpis' => [
                'globalPnl' => $globalPnl,
                'globalPnlWarning' => $globalPnlWarning,
                'globalPnlWarningDetail' => $globalPnlWarningDetail,
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