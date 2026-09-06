<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * TNSVT HeatmapService — Fase 5.
 *
 * Builds a GitHub-style activity heatmap for the last N weeks (default 12).
 * Each cell is a calendar day; the intensity is the count of distinct
 * "learning events" (lesson completed, task submitted) on that day.
 *
 * Output format matches what the frontend controller expects:
 *   { weeks: 12, total_days: 84, total_events: N, days: [{date, count, level}, ...] }
 * where `level` is 0..4 (bucketized intensity).
 */
class HeatmapService
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function getHeatmap(string $userCode, int $weeks = 12): array
    {
        $weeks = max(1, min(52, $weeks));
        $totalDays = $weeks * 7;
        $since = new \DateTimeImmutable("-{$totalDays} days");

        $conn = $this->em->getConnection();
        $userId = (int) $conn->fetchOne('SELECT id FROM users WHERE code = :c', ['c' => $userCode]);
        $rows = $conn->fetchAllAssociative(
            'SELECT activity_date, total FROM (
                SELECT DATE(completed_at) AS activity_date, COUNT(*) AS total
                FROM campus_lesson_progress
                WHERE user_code = :userCode AND completed = 1 AND completed_at >= :since
                GROUP BY DATE(completed_at)
                UNION ALL
                SELECT DATE(submitted_at) AS activity_date, COUNT(*) AS total
                FROM task_submissions
                WHERE user_id = :userId AND submitted_at >= :since
                GROUP BY DATE(submitted_at)
                UNION ALL
                SELECT DATE(updated_at) AS activity_date, COUNT(*) AS total
                FROM tasks
                WHERE assigned_by_id IN (SELECT id FROM users WHERE code = :userCode)
                  AND status = :statusApproved
                  AND updated_at >= :since
                GROUP BY DATE(updated_at)
            ) AS activities
            GROUP BY activity_date
            ORDER BY activity_date ASC',
            [
                'userCode' => $userCode,
                'userId' => $userId,
                'since' => $since->format('Y-m-d'),
                'statusApproved' => 'approved',
            ]
        );

        // Build a full sequence of days (including zeros)
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['activity_date']] = (int) $r['total'];
        }

        $totalEvents = 0;
        $maxCount = 0;
        foreach ($byDate as $n) {
            $totalEvents += $n;
            $maxCount = max($maxCount, $n);
        }

        // Build days sequence (ordered chronologically)
        $days = [];
        $start = $since->modify('+1 day'); // start counting from tomorrow-of-since
        // Align to the start of a week (Monday) for nice grid layout
        $dow = (int) $start->format('N'); // 1 = Mon, 7 = Sun
        if ($dow > 1) {
            $start = $start->modify('-' . ($dow - 1) . ' days');
        }
        // End: today
        $end = new \DateTimeImmutable('today');
        // Align end to Sunday
        $endDow = (int) $end->format('N');
        if ($endDow < 7) {
            $end = $end->modify('+' . (7 - $endDow) . ' days');
        }

        $cursor = clone $start;
        while ($cursor <= $end) {
            $dateStr = $cursor->format('Y-m-d');
            $count = $byDate[$dateStr] ?? 0;
            $days[] = [
                'date' => $dateStr,
                'count' => $count,
                'level' => $this->intensityLevel($count, $maxCount),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'weeks' => $weeks,
            'total_days' => count($days),
            'total_events' => $totalEvents,
            'days' => $days,
        ];
    }

    private function intensityLevel(int $count, int $max): int
    {
        if ($count <= 0) return 0;
        if ($max <= 1) return 4;
        $ratio = $count / $max;
        if ($ratio < 0.25) return 1;
        if ($ratio < 0.5) return 2;
        if ($ratio < 0.75) return 3;
        return 4;
    }
}
