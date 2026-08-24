<?php

namespace App\Controller\Api;

use App\Entity\JournalEntry;
use App\Entity\User;
use App\Repository\JournalEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/leaderboard')]
class LeaderboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {}

    #[Route('', name: 'api_leaderboard', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $users = $this->userRepository->findBy(['active' => true]);
        $stats = [];
        /** @var JournalEntryRepository $journalRepo */
        $journalRepo = $this->em->getRepository(JournalEntry::class);

        foreach ($users as $user) {
            $trades = $journalRepo->findAllForUser($user->getCode());
            if (count($trades) === 0) continue;

            $total = count($trades);
            $wins = 0;
            $losses = 0;
            $totalPnl = 0;
            $grossWin = 0;
            $grossLoss = 0;
            $maxWin = 0;
            $maxLoss = 0;

            foreach ($trades as $t) {
                $pnl = (float) ($t->getPnl() ?? 0);
                $totalPnl += $pnl;
                if ($t->getResult() === JournalEntry::RESULT_WIN) {
                    $wins++;
                    $grossWin += $pnl;
                    if ($pnl > $maxWin) $maxWin = $pnl;
                } elseif ($t->getResult() === JournalEntry::RESULT_LOSS) {
                    $losses++;
                    $grossLoss += abs($pnl);
                    if (abs($pnl) > $maxLoss) $maxLoss = abs($pnl);
                }
            }

            $winRate = $total > 0 ? round($wins / $total * 100, 1) : 0;
            $profitFactor = $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : ($grossWin > 0 ? 999 : 0);

            $stats[] = [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'total_trades' => $total,
                'wins' => $wins,
                'losses' => $losses,
                'win_rate' => $winRate,
                'total_pnl' => round($totalPnl, 2),
                'profit_factor' => $profitFactor,
                'avg_win' => $wins > 0 ? round($grossWin / $wins, 2) : 0,
                'avg_loss' => $losses > 0 ? round($grossLoss / $losses, 2) : 0,
                'best_trade' => round($maxWin, 2),
                'worst_trade' => round($maxLoss, 2),
            ];
        }

        usort($stats, fn($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);

        return $this->json(array_slice($stats, 0, 50));
    }
}
