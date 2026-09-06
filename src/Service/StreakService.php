<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TNSVT StreakService — Fase 5.
 *
 * Computes a user's current consecutive-day learning streak based on the
 * distinct dates of `campus_lesson_progress.completed_at` (and task
 * submissions as a secondary signal).
 *
 * A streak is the number of consecutive calendar days ending today (or
 * yesterday, with a 1-day grace) on which the user did at least one
 * tracked activity.
 */
class StreakService
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Returns the streak info for the given user.
     *
     * @return array{
     *   current: int,
     *   longest: int,
     *   last_active_date: ?string,
     *   today_active: bool,
     *   history: array<int, array{date: string, count: int}>,
     * }
     */
    public function getStreak(string $userCode, int $historyDays = 30): array
    {
        $conn = $this->em->getConnection();

        // Resolve user_id once so we can join task_submissions cleanly
        $userId = (int) $conn->fetchOne('SELECT id FROM users WHERE code = :c', ['c' => $userCode]);

        // Distinct activity dates (campus_lesson_progress + task_submissions + task completions)
        $rows = $conn->fetchAllAssociative(
            'SELECT activity_date, total FROM (
                SELECT DATE(completed_at) AS activity_date, COUNT(*) AS total
                FROM campus_lesson_progress
                WHERE user_code = :userCode AND completed = 1 AND completed_at IS NOT NULL
                GROUP BY DATE(completed_at)
                UNION ALL
                SELECT DATE(submitted_at) AS activity_date, COUNT(*) AS total
                FROM task_submissions
                WHERE user_id = :userId AND submitted_at IS NOT NULL
                GROUP BY DATE(submitted_at)
            ) AS activities
            WHERE activity_date >= :since
            GROUP BY activity_date
            ORDER BY activity_date ASC',
            ['userCode' => $userCode, 'userId' => $userId, 'since' => (new \DateTimeImmutable("-{$historyDays} days"))->format('Y-m-d')]
        );

        $history = [];
        foreach ($rows as $r) {
            $history[] = ['date' => $r['activity_date'], 'count' => (int) $r['total']];
        }

        $dates = array_column($history, 'date');
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');

        $current = 0;
        $longest = 0;
        $streak = 0;
        $prev = null;

        // Compute longest streak overall
        foreach ($dates as $d) {
            $cur = new \DateTimeImmutable($d);
            if ($prev === null) {
                $streak = 1;
            } else {
                $diff = (new \DateTimeImmutable($prev))->diff($cur)->days;
                $streak = ($diff === 1) ? $streak + 1 : 1;
            }
            $longest = max($longest, $streak);
            $prev = $d;
        }

        // Compute current streak ending today (or yesterday grace)
        $current = 0;
        if (!empty($dates)) {
            $lastDate = end($dates);
            if ($lastDate === $today || $lastDate === $yesterday) {
                $current = 1;
                $prev = new \DateTimeImmutable($lastDate);
                // Walk backwards
                for ($i = count($dates) - 2; $i >= 0; $i--) {
                    $d = new \DateTimeImmutable($dates[$i]);
                    $diff = $prev->diff($d)->days;
                    if ($diff === 1) {
                        $current++;
                        $prev = $d;
                    } else {
                        break;
                    }
                }
            }
        }

        return [
            'current' => $current,
            'longest' => $longest,
            'last_active_date' => $lastDate ?? null,
            'today_active' => in_array($today, $dates, true),
            'history' => $history,
        ];
    }
}
