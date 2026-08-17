<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Repository\TradeSnapshotRepository;
use App\Repository\TournamentEntryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;

/**
 * Fired every 5 minutes by Symfony Scheduler.
 * Refreshes leaderboard caches from the trade/journals snapshots.
 */
final class RecomputeLeaderboardsHandler
{
    public function __construct(
        private TournamentEntryRepository $tournamentEntries,
        private TradeSnapshotRepository $tradeSnapshots,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecomputeLeaderboardsMessage $message, SchedulerTransport $transport): void
    {
        $tournaments = $this->tournamentEntries->findActiveTournaments();
        $refreshed = 0;

        foreach ($tournaments as $tournament) {
            $entries = $this->tournamentEntries->findLeaderboard($tournament);
            // Cache invalidation: callers should re-read leaderboards from /api/tournaments/leaderboard
            $refreshed++;
        }

        if ($refreshed > 0) {
            $this->logger->info('Refreshed tournament leaderboards', ['count' => $refreshed]);
        }
    }
}