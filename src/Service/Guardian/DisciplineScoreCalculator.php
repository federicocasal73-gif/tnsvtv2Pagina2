<?php

declare(strict_types=1);

namespace App\Service\Guardian;

use App\Entity\User;
use App\Repository\PropFirmAlertRepository;

/**
 * DisciplineScoreCalculator — Phase 1 (read-only).
 *
 * Computes a 0–100 discipline score from existing data sources.
 *
 * The formula is intentionally simple and explainable. It can be tuned later
 * (per-user weight, decay over time, mentor overrides, …) without changing
 * the public API.
 *
 * Returns:
 *   - score (int 0–100)
 *   - breakdown (array of human-readable reasons that contributed)
 *   - tier (one of: 'elite', 'strong', 'steady', 'caution', 'risk')
 */
class DisciplineScoreCalculator
{
    public const TIER_ELITE = 'elite';
    public const TIER_STRONG = 'strong';
    public const TIER_STEADY = 'steady';
    public const TIER_CAUTION = 'caution';
    public const TIER_RISK = 'risk';

    public const STARTING_SCORE = 100;

    public function __construct(
        private PropFirmAlertRepository $alertRepo,
        private GuardianSignalCollector $signalCollector,
    ) {}

    /**
     * @return array{
     *     score: int,
     *     tier: string,
     *     breakdown: array<int, array{label: string, delta: int, source: string}>,
     *     computed_at: string,
     * }
     */
    public function compute(User $user): array
    {
        $score = self::STARTING_SCORE;
        $breakdown = [];

        // 1. Active prop firm warnings (last 7 days)
        // Note: PropFirmAlertRepository exposes findRecentByUser(user, limit).
        // We pull the most recent 50 and filter by date here — the score
        // is a snapshot, no need to scan full history.
        $cutoff = (new \DateTimeImmutable())->modify('-7 days');
        $recentAlerts = $this->alertRepo->findRecentByUser($user, 50);
        foreach ($recentAlerts as $alert) {
            if ($alert->getCreatedAt() === null || $alert->getCreatedAt() < $cutoff) {
                continue;
            }
            $delta = match ($alert->getSeverity()) {
                \App\Entity\PropFirmAlert::SEVERITY_DANGER => -25,
                \App\Entity\PropFirmAlert::SEVERITY_WARNING => -10,
                default => -3,
            };
            if ($delta === 0) continue;

            $score += $delta;
            $breakdown[] = [
                'label' => $alert->getMessage(),
                'delta' => $delta,
                'source' => 'prop_firm_alert',
            ];
        }

        // 2. Signals from GuardianSignalCollector (discipline + macro only)
        foreach ($this->signalCollector->collect($user) as $signal) {
            if (!str_starts_with($signal->type, 'discipline.')
                && !str_starts_with($signal->type, 'macro.')) {
                continue;
            }

            $delta = match ($signal->severity) {
                GuardianSignal::SEVERITY_DANGER => -15,
                GuardianSignal::SEVERITY_WARNING => -10,
                GuardianSignal::SEVERITY_INFO => -3,
                default => 0,
            };
            if ($delta === 0) continue;

            $score += $delta;
            $breakdown[] = [
                'label' => $signal->title,
                'delta' => $delta,
                'source' => $signal->type,
            ];
        }

        // Clamp
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'tier' => $this->tierFor($score),
            'breakdown' => $breakdown,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function tierFor(int $score): string
    {
        return match (true) {
            $score >= 90 => self::TIER_ELITE,
            $score >= 75 => self::TIER_STRONG,
            $score >= 60 => self::TIER_STEADY,
            $score >= 40 => self::TIER_CAUTION,
            default => self::TIER_RISK,
        };
    }
}
