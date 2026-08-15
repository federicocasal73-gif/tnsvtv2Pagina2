<?php

declare(strict_types=1);

namespace App\Service\Guardian;

use App\Entity\User;
use App\Repository\DiaryEntryRepository;
use App\Repository\JournalEntryRepository;
use App\Repository\PropFirmAccountRepository;
use App\Service\Macro\NoTradeWindowService;
use App\Service\PropFirmRuleChecker;

/**
 * GuardianSignalCollector — Phase 1 (read-only).
 *
 * Orchestrates existing Guardian atoms to produce a unified list of signals for
 * a user. Read-only: it does NOT persist anything, does NOT mutate the database.
 *
 * Inputs (all already in the codebase):
 *  - PropFirmRuleChecker (rule engine)
 *  - NoTradeWindowService (macro no-trade windows)
 *  - PropFirmAccountRepository (active accounts per user)
 *  - JournalEntryRepository (recent trade activity)
 *  - DiaryEntryRepository (recent diary activity)
 *
 * Output: ordered list of GuardianSignal value objects (most severe first).
 *
 * The collector is safe to call on every Sanctum Home render. It is cheap: it
 * reads at most a handful of rows per user.
 */
class GuardianSignalCollector
{
    /** Streak days below this trigger a "no journal" hint */
    public const NO_JOURNAL_THRESHOLD_DAYS = 5;

    /** Diary absence (days) that triggers a soft hint */
    public const NO_DIARY_THRESHOLD_DAYS = 7;

    public function __construct(
        private PropFirmRuleChecker $ruleChecker,
        private NoTradeWindowService $noTradeWindow,
        private PropFirmAccountRepository $pfaRepo,
        private JournalEntryRepository $journalRepo,
        private DiaryEntryRepository $diaryRepo,
    ) {}

    /**
     * @return GuardianSignal[] Ordered: severity DESC, then risk > macro > discipline.
     */
    public function collect(User $user): array
    {
        $signals = [];

        // 1. Risk signals from PropFirmRuleChecker (per active account)
        $signals = array_merge($signals, $this->riskSignals($user));

        // 2. Macro signals from NoTradeWindowService (global, not per-user)
        $signals = array_merge($signals, $this->macroSignals());

        // 3. Discipline signals (no journal / no diary)
        $signals = array_merge($signals, $this->disciplineSignals($user));

        return $this->sort($signals);
    }

    /**
     * @return GuardianSignal[]
     */
    private function riskSignals(User $user): array
    {
        $signals = [];
        $accounts = $this->pfaRepo->findActiveByUser($user);

        foreach ($accounts as $account) {
            $status = $this->ruleChecker->getStatus($account);
            if (!is_array($status)) continue;

            $drawdown = (float) ($status['drawdown_pct'] ?? 0);
            $dailyLoss = (float) ($status['daily_loss_pct'] ?? 0);
            $maxDD = (float) ($status['max_drawdown_pct'] ?? 0);
            $maxDL = (float) ($status['max_daily_loss_pct'] ?? 0);

            // ── Drawdown signals ──
            if ($maxDD > 0 && $drawdown >= $maxDD * 0.9 && $drawdown < $maxDD) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_RISK_DRAWDOWN_NEAR,
                    severity: GuardianSignal::SEVERITY_WARNING,
                    title: 'Drawdown cerca del límite',
                    message: sprintf('Drawdown actual %.1f%% sobre máximo de %.0f%%', $drawdown, $maxDD),
                    actionLabel: 'Revisar plan',
                    actionRoute: '/journal',
                    context: [
                        'account_id' => $account->getId(),
                        'drawdown_pct' => $drawdown,
                        'max_drawdown_pct' => $maxDD,
                    ],
                );
            }

            if ($maxDD > 0 && $drawdown >= $maxDD) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_RISK_DRAWDOWN_NEAR,
                    severity: GuardianSignal::SEVERITY_DANGER,
                    title: 'Drawdown violado',
                    message: sprintf('Drawdown %.1f%% excede el máximo %.0f%%', $drawdown, $maxDD),
                    actionLabel: 'Ver estado',
                    actionRoute: '/journal',
                    context: [
                        'account_id' => $account->getId(),
                        'drawdown_pct' => $drawdown,
                    ],
                );
            }

            // ── Daily loss signals ──
            if ($maxDL > 0 && $dailyLoss >= $maxDL && $dailyLoss < $maxDL * 1.2) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_RISK_DAILY_LOSS_CRITICAL,
                    severity: GuardianSignal::SEVERITY_DANGER,
                    title: 'Pérdida diaria crítica',
                    message: sprintf('Hoy %.1f%% de pérdida, límite %.0f%%', $dailyLoss, $maxDL),
                    actionLabel: 'Parar y revisar',
                    actionRoute: '/journal',
                    context: [
                        'account_id' => $account->getId(),
                        'daily_loss_pct' => $dailyLoss,
                        'max_daily_loss_pct' => $maxDL,
                    ],
                );
            } elseif ($maxDL > 0 && $dailyLoss >= $maxDL * 0.7) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_RISK_DAILY_LOSS_NEAR,
                    severity: GuardianSignal::SEVERITY_WARNING,
                    title: 'Pérdida diaria acercándose al límite',
                    message: sprintf('Hoy %.1f%% de pérdida (límite %.0f%%)', $dailyLoss, $maxDL),
                    actionLabel: 'Revisar riesgo',
                    actionRoute: '/journal',
                    context: [
                        'account_id' => $account->getId(),
                        'daily_loss_pct' => $dailyLoss,
                        'max_daily_loss_pct' => $maxDL,
                    ],
                );
            }
        }

        return $signals;
    }

    /**
     * @return GuardianSignal[]
     */
    private function macroSignals(): array
    {
        $signals = [];

        $active = $this->noTradeWindow->getActiveWindow();
        if ($active !== null) {
            $signals[] = new GuardianSignal(
                type: GuardianSignal::TYPE_MACRO_NO_TRADE_ACTIVE,
                severity: GuardianSignal::SEVERITY_WARNING,
                title: 'Ventana de no-trade activa',
                message: sprintf(
                    '%s %s — evento macro de alto impacto en curso',
                    $active['country'] ?? '?',
                    $active['title'] ?? 'evento macro',
                ),
                actionLabel: 'Ver calendario',
                actionRoute: '/calendar',
                context: [
                    'event_id' => $active['event_id'] ?? null,
                    'ends_at' => $active['end']->format(\DateTimeInterface::ATOM),
                ],
            );
        }

        $next = $this->noTradeWindow->getNextWindow();
        if ($next !== null) {
            $secondsUntil = (new \DateTimeImmutable())->getTimestamp() - $next['start']->getTimestamp();
            // Only signal if next window starts within the next 30 minutes
            if ($secondsUntil > -1800 && $secondsUntil <= 1800) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_MACRO_NO_TRADE_UPCOMING,
                    severity: GuardianSignal::SEVERITY_INFO,
                    title: 'Próxima ventana de no-trade',
                    message: sprintf(
                        '%s %s — ventana inicia pronto',
                        $next['country'] ?? '?',
                        $next['title'] ?? 'evento macro',
                    ),
                    actionLabel: 'Ver calendario',
                    actionRoute: '/calendar',
                    context: [
                        'event_id' => $next['event_id'] ?? null,
                        'starts_at' => $next['start']->format(\DateTimeInterface::ATOM),
                    ],
                );
            }
        }

        return $signals;
    }

    /**
     * @return GuardianSignal[]
     */
    private function disciplineSignals(User $user): array
    {
        $signals = [];

        // Journal recency
        $latestJournal = $this->latestJournalDate($user);
        if ($latestJournal !== null) {
            $daysSince = (int) $latestJournal->diff(new \DateTimeImmutable('today'))->days;
            if ($daysSince >= self::NO_JOURNAL_THRESHOLD_DAYS) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_DISCIPLINE_NO_JOURNAL,
                    severity: GuardianSignal::SEVERITY_INFO,
                    title: 'Sin trades registrados',
                    message: sprintf('Hace %d días que no registrás un trade', $daysSince),
                    actionLabel: 'Registrar trade',
                    actionRoute: '/journal',
                    context: ['days_since' => $daysSince],
                );
            }
        }

        // Diary recency
        $latestDiary = $this->latestDiaryDate($user);
        if ($latestDiary !== null) {
            $daysSince = (int) $latestDiary->diff(new \DateTimeImmutable('today'))->days;
            if ($daysSince >= self::NO_DIARY_THRESHOLD_DAYS) {
                $signals[] = new GuardianSignal(
                    type: GuardianSignal::TYPE_DISCIPLINE_NO_DIARY,
                    severity: GuardianSignal::SEVERITY_INFO,
                    title: 'Sin entradas en el diario',
                    message: sprintf('Hace %d días que no escribís en el diario cifrado', $daysSince),
                    actionLabel: 'Abrir diario',
                    actionRoute: '/diario',
                    context: ['days_since' => $daysSince],
                );
            }
        }

        return $signals;
    }

    private function latestJournalDate(User $user): ?\DateTimeImmutable
    {
        $entries = $this->journalRepo->findBy(
            ['userCode' => $user->getCode()],
            ['updatedAt' => 'DESC'],
            1,
        );
        if (empty($entries)) {
            return null;
        }
        return $entries[0]->getUpdatedAt();
    }

    private function latestDiaryDate(User $user): ?\DateTimeImmutable
    {
        $entries = $this->diaryRepo->findBy(
            ['user' => $user],
            ['updatedAt' => 'DESC'],
            1,
        );
        if (empty($entries)) {
            return null;
        }
        $date = $entries[0]->getUpdatedAt();
        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /**
     * @param GuardianSignal[] $signals
     * @return GuardianSignal[]
     */
    private function sort(array $signals): array
    {
        $severityRank = [
            GuardianSignal::SEVERITY_DANGER => 3,
            GuardianSignal::SEVERITY_WARNING => 2,
            GuardianSignal::SEVERITY_INFO => 1,
        ];

        usort($signals, function (GuardianSignal $a, GuardianSignal $b) use ($severityRank) {
            return ($severityRank[$b->severity] ?? 0) <=> ($severityRank[$a->severity] ?? 0);
        });

        return $signals;
    }
}
