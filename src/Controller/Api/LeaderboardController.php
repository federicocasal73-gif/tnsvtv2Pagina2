<?php

namespace App\Controller\Api;

use App\Entity\JournalEntry;
use App\Entity\User;
use App\Repository\GameLeaderboardEntryRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/leaderboard')]
class LeaderboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private GameLeaderboardEntryRepository $leaderboardRepo,
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

    #[IsGranted('ROLE_USER')]
    #[Route('/game', name: 'api_leaderboard_game', methods: ['GET'])]
    public function gameLeaderboard(Request $request): JsonResponse
    {
        $type = $request->query->get('type', 'coins');
        $period = $request->query->get('period', 'all_time');
        $limit = min((int) $request->query->get('limit', 100), 100);
        $offset = max(0, (int) $request->query->get('offset', 0));

        $validTypes = ['coins', 'reputation', 'season_xp', 'rank_points'];
        $validPeriods = ['all_time', 'weekly', 'monthly'];

        if (!in_array($type, $validTypes)) {
            return $this->json(['error' => 'Invalid type'], 400);
        }
        if (!in_array($period, $validPeriods)) {
            return $this->json(['error' => 'Invalid period'], 400);
        }

        $entries = $this->leaderboardRepo->getLeaderboard($type, $period, $limit, $offset);

        $leaderboard = [];
        foreach ($entries as $index => $entry) {
            $leaderboard[] = [
                'rank' => $offset + $index + 1,
                'code' => $entry->getUser()->getCode(),
                'name' => $entry->getUser()->getName(),
                'avatar' => $entry->getUser()->getAvatar(),
                'score' => $entry->getScore(),
                'userId' => $entry->getUser()->getId(),
            ];
        }

        $user = $this->getUser();
        $userRank = null;
        $userScore = 0;
        $userEntry = $this->leaderboardRepo->findOneBy([
            'user' => $user,
            'leaderboardType' => $type,
            'period' => $period,
        ]);

        if ($userEntry) {
            $userScore = $userEntry->getScore();
            $userRank = $this->leaderboardRepo->getUserRank($user->getId(), $type, $period);
        }

        return $this->json([
            'leaderboard' => $leaderboard,
            'pagination' => [
                'total' => $this->getTotalCount($type, $period),
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => ($offset + $limit) < $this->getTotalCount($type, $period),
            ],
            'currentUser' => [
                'rank' => $userRank,
                'score' => $userScore,
            ],
            'filters' => [
                'type' => $type,
                'period' => $period,
            ],
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/game/rank', name: 'api_leaderboard_user_rank', methods: ['GET'])]
    public function getUserRank(Request $request): JsonResponse
    {
        $type = $request->query->get('type', 'coins');
        $period = $request->query->get('period', 'all_time');

        $user = $this->getUser();
        $rank = $this->leaderboardRepo->getUserRank($user->getId(), $type, $period);
        $entry = $this->leaderboardRepo->findOneBy([
            'user' => $user,
            'leaderboardType' => $type,
            'period' => $period,
        ]);

        return $this->json([
            'rank' => $rank,
            'score' => $entry ? $entry->getScore() : 0,
            'type' => $type,
            'period' => $period,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/game/update', name: 'api_leaderboard_update', methods: ['POST'])]
    public function updateScore(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = $request->toArray();

        $type = $data['type'] ?? null;
        $score = $data['score'] ?? null;
        $period = $data['period'] ?? GameLeaderboardEntry::PERIOD_ALL_TIME;
        $seasonId = $data['seasonId'] ?? null;

        if (!$type || $score === null) {
            return $this->json(['error' => 'Missing type or score'], 400);
        }

        $entry = $this->leaderboardRepo->updateOrCreate(
            $user->getId(),
            $type,
            $period,
            (int) $score,
            $seasonId
        );

        return $this->json([
            'success' => true,
            'rank' => $this->leaderboardRepo->getUserRank($user->getId(), $type, $period),
            'score' => $entry->getScore(),
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/game/top', name: 'api_leaderboard_top', methods: ['GET'])]
    public function getTopPlayers(Request $request): JsonResponse
    {
        $type = $request->query->get('type', 'coins');
        $limit = min((int) $request->query->get('limit', 10), 50);

        $entries = $this->leaderboardRepo->getTopPlayersByType($type, $limit);

        $topPlayers = [];
        foreach ($entries as $index => $entry) {
            $topPlayers[] = [
                'rank' => $index + 1,
                'code' => $entry->getUser()->getCode(),
                'name' => $entry->getUser()->getName(),
                'avatar' => $entry->getUser()->getAvatar(),
                'score' => $entry->getScore(),
            ];
        }

        return $this->json(['topPlayers' => $topPlayers, 'type' => $type]);
    }

    private function getTotalCount(string $type, string $period): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('COUNT(e.id)')
           ->from(\App\Entity\GameLeaderboardEntry::class, 'e')
           ->where('e.leaderboardType = :type')
           ->andWhere('e.period = :period')
           ->setParameter('type', $type)
           ->setParameter('period', $period);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
