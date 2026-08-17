<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * Triggered every 5 minutes.
 * Recomputes leaderboards from recent trade data (caches for fast reads).
 */
final class RecomputeLeaderboardsMessage
{
}