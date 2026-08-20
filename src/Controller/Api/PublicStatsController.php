<?php

namespace App\Controller\Api;

use App\Repository\JournalEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public')]
class PublicStatsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private JournalEntryRepository $entryRepository,
    ) {}

    /**
     * Public stats for the homepage landing.
     * Returns only aggregate counts — never user-specific data.
     */
    #[Route('/stats', name: 'api_public_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        // Active traders count
        $activeTraders = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM users WHERE active = 1');

        // Trades today
        $tradesToday = (int) $this->em->getConnection()
            ->fetchOne("SELECT COUNT(*) FROM journal_entries WHERE DATE(date) = CURDATE()");

        // Total trades (all-time)
        $totalTrades = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM journal_entries');

        // Global PnL
        $globalPnl = (float) $this->em->getConnection()
            ->fetchOne('SELECT COALESCE(SUM(pnl), 0) FROM journal_entries');

        return $this->json([
            'success' => true,
            'stats' => [
                'active_traders' => $activeTraders,
                'trades_today' => $tradesToday,
                'total_trades' => $totalTrades,
                'global_pnl' => round($globalPnl, 2),
            ],
        ]);
    }
}
