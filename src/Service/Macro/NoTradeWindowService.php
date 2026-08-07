<?php

namespace App\Service\Macro;

use App\Repository\EconomicReminderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * NoTradeWindowService — Phase 5 (Hilos del Mundo).
 *
 * Implements the trading discipline rule:
 *   "Do not trade 15 minutes before nor 15 minutes after a high-impact
 *    macroeconomic event (event_importance >= 3)."
 *
 * The 15-minute window is derived from the difference between
 * `remind_at` and `event_time` fields. Most legacy events have
 * remind_at = event_time - 15min, so we can use that as-is.
 *
 * Window logic:
 *   - If the event has event_importance < 3 → ignore (low/medium impact)
 *   - Otherwise, the window is:
 *     start: event_time - 15 minutes
 *     end:   event_time + 15 minutes
 *   - A no-trade-window is "active" if `now` is within the window
 *   - We can fall back to using `remind_at` directly if `event_time` is missing
 */
class NoTradeWindowService
{
    /** Window length on each side of the event, in minutes */
    public const WINDOW_BEFORE_MIN = 15;
    public const WINDOW_AFTER_MIN = 15;

    /** High-impact threshold (1=low, 2=med, 3=high) */
    public const HIGH_IMPACT = 3;

    public function __construct(
        private EconomicReminderRepository $repo,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Get the active no-trade window (if any) for the current moment.
     * Returns the active event or null if no window is active.
     */
    public function getActiveWindow(): ?array
    {
        $now = new \DateTimeImmutable('now');
        $windows = $this->computeWindows();

        foreach ($windows as $w) {
            if ($now >= $w['start'] && $now <= $w['end']) {
                return $w;
            }
        }
        return null;
    }

    /**
     * Get the next upcoming no-trade window (the one that will activate next).
     */
    public function getNextWindow(): ?array
    {
        $now = new \DateTimeImmutable('now');
        $windows = $this->computeWindows();
        $upcoming = array_filter($windows, fn($w) => $w['start'] > $now);
        if (empty($upcoming)) return null;
        $sorted = $upcoming;
        usort($sorted, fn($a, $b) => $a['start'] <=> $b['start']);
        return $sorted[0];
    }

    /**
     * Get the seconds remaining until the next window starts (or until current ends).
     * Returns null if no upcoming or active windows.
     */
    public function secondsUntilNextWindow(): ?int
    {
        $now = new \DateTimeImmutable('now');
        $active = $this->getActiveWindow();
        if ($active) {
            return $active['end']->getTimestamp() - $now->getTimestamp();
        }
        $next = $this->getNextWindow();
        if ($next) {
            return $next['start']->getTimestamp() - $now->getTimestamp();
        }
        return null;
    }

    /**
     * Fetch all upcoming+active high-impact events and compute their windows.
     * Returns an array of associative arrays:
     *   [
     *     'event_id' => int,
     *     'title' => string,
     *     'country' => string,
     *     'currency' => string,
     *     'importance' => int (3),
     *     'event_time' => DateTimeImmutable,
     *     'start' => DateTimeImmutable (event_time - 15min),
     *     'end' => DateTimeImmutable (event_time + 15min),
     *     'is_active' => bool,
     *   ]
     */
    public function computeWindows(?int $limit = 20, ?int $hoursAhead = 168): array
    {
        $cutoff = (new \DateTimeImmutable('now'))->modify("-30 minutes"); // include recently past
        $ahead = (new \DateTimeImmutable('now'))->modify("+{$hoursAhead} hours");

        $events = $this->em->getConnection()->fetchAllAssociative(
            "SELECT id, event_title, event_country_code, event_currency, event_importance, event_date, event_time, remind_at
             FROM economic_reminders
             WHERE event_importance >= :min_imp
               AND (remind_at IS NOT NULL OR (event_date IS NOT NULL AND event_time IS NOT NULL))
             ORDER BY COALESCE(remind_at, CONCAT(event_date, ' ', event_time)) ASC
             LIMIT $limit",
            ['min_imp' => self::HIGH_IMPACT]
        );

        $now = new \DateTimeImmutable('now');
        $windows = [];
        foreach ($events as $e) {
            // Derive the event time: prefer remind_at + 15min (legacy convention)
            $eventTime = null;
            if (!empty($e['remind_at'])) {
                $eventTime = (new \DateTimeImmutable($e['remind_at']))->modify('+15 minutes');
            } elseif (!empty($e['event_date']) && !empty($e['event_time'])) {
                $eventTime = new \DateTimeImmutable($e['event_date'] . ' ' . $e['event_time']);
            }
            if (!$eventTime) continue;

            $start = $eventTime->modify('-' . self::WINDOW_BEFORE_MIN . ' minutes');
            $end = $eventTime->modify('+' . self::WINDOW_AFTER_MIN . ' minutes');
            if ($end < $cutoff || $start > $ahead) continue;

            $windows[] = [
                'event_id' => (int)$e['id'],
                'title' => $e['event_title'],
                'country' => $e['event_country_code'] ?? '?',
                'currency' => $e['event_currency'] ?? '?',
                'importance' => (int)$e['event_importance'],
                'event_time' => $eventTime,
                'start' => $start,
                'end' => $end,
                'is_active' => ($now >= $start && $now <= $end),
            ];
        }
        return $windows;
    }
}