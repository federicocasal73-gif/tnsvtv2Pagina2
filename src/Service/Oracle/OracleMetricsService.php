<?php

namespace App\Service\Oracle;

use App\Entity\JournalEntry;
use App\Entity\TradeSnapshot;
use App\Repository\JournalEntryRepository;
use App\Repository\TradeSnapshotRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Oracle — Computes the TNSVT Reino v2 metrics for the Oráculo de Métricas.
 *
 * Three metric families:
 *  - Emotional Bias Map: analytical vs emotional score per trade
 *  - Faith vs Logic gauge: percentage of trades aligned with a "logic" signal
 *  - Session Performance: aggregated win/loss/pnl over time
 *
 * All metrics are computed on-the-fly from the migrated journal_entries
 * (no scheduled command needed for Phase 4 initial release).
 */
class OracleMetricsService
{
    public function __construct(
        private JournalEntryRepository $journalRepo,
        private TradeSnapshotRepository $snapshotRepo,
        private UserRepository $userRepo,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Compute Emotional Bias Map for a user over a date range.
     * Returns array of {date, asset, analytical, emotional, pnl, result}.
     * - analytical = 1 if symbol matches a "planned" tier (top traded), else 0
     * - emotional = 1 - analytical (proxy: trades on non-preferred assets are "emotional")
     */
    public function getEmotionalBiasMap(string $userCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $entries = $this->journalRepo->createQueryBuilder('j')
            ->where('j.userCode = :u')
            ->andWhere('j.date >= :from')
            ->andWhere('j.date <= :to')
            ->setParameter('u', $userCode)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('j.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Determine the user's most-traded asset (proxy for "logic/planned")
        $assetCounts = [];
        foreach ($entries as $e) {
            $a = $e->getAsset() ?? 'UNKNOWN';
            $assetCounts[$a] = ($assetCounts[$a] ?? 0) + 1;
        }
        arsort($assetCounts);
        $preferredAsset = $assetCounts ? array_key_first($assetCounts) : null;

        $map = [];
        foreach ($entries as $e) {
            $a = $e->getAsset() ?? 'UNKNOWN';
            $analytical = ($a === $preferredAsset) ? 1.0 : 0.0;
            $emotional = 1.0 - $analytical;
            $map[] = [
                'date' => $e->getDate()?->format('Y-m-d'),
                'asset' => $a,
                'analytical' => $analytical,
                'emotional' => $emotional,
                'pnl' => (float)$e->getPnl(),
                'result' => $e->getResult(),
            ];
        }
        return $map;
    }

    /**
     * Compute Faith vs Logic gauge for a user.
     * "Logic" trades are WIN + consistent asset (same as preferred).
     * "Faith" trades are everything else.
     * Returns ['logic' => 0-100, 'faith' => 0-100, 'total' => int].
     */
    public function getFaithVsLogicGauge(string $userCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $entries = $this->journalRepo->createQueryBuilder('j')
            ->where('j.userCode = :u')
            ->andWhere('j.date >= :from')
            ->andWhere('j.date <= :to')
            ->setParameter('u', $userCode)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $total = count($entries);
        if ($total === 0) {
            return ['logic' => 0, 'faith' => 0, 'total' => 0, 'win_rate' => 0];
        }

        // Logic = WIN trades on preferred asset
        $assetCounts = [];
        foreach ($entries as $e) {
            $a = $e->getAsset() ?? 'UNKNOWN';
            $assetCounts[$a] = ($assetCounts[$a] ?? 0) + 1;
        }
        arsort($assetCounts);
        $preferredAsset = $assetCounts ? array_key_first($assetCounts) : null;

        $logic = 0;
        $win = 0;
        foreach ($entries as $e) {
            $a = $e->getAsset() ?? 'UNKNOWN';
            $r = $e->getResult();
            if ($r === 'WIN' || $r === 'win') {
                $win++;
                if ($a === $preferredAsset) $logic++;
            }
        }

        $logicPct = round(($logic / $total) * 100, 1);
        $winPct = round(($win / $total) * 100, 1);

        return [
            'logic' => $logicPct,
            'faith' => round(100 - $logicPct, 1),
            'total' => $total,
            'win_rate' => $winPct,
            'preferred_asset' => $preferredAsset,
        ];
    }

    /**
     * Compute Session Performance for a user: aggregated win/loss/pnl per day.
     * Returns array of daily snapshots.
     */
    public function getSessionPerformance(string $userCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $entries = $this->journalRepo->createQueryBuilder('j')
            ->where('j.userCode = :u')
            ->andWhere('j.date >= :from')
            ->andWhere('j.date <= :to')
            ->setParameter('u', $userCode)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('j.date', 'ASC')
            ->getQuery()
            ->getResult();

        $byDate = [];
        foreach ($entries as $e) {
            $d = $e->getDate()?->format('Y-m-d');
            if (!isset($byDate[$d])) {
                $byDate[$d] = ['date' => $d, 'trades' => 0, 'wins' => 0, 'losses' => 0, 'be' => 0, 'pnl' => 0.0];
            }
            $byDate[$d]['trades']++;
            $r = $e->getResult();
            if ($r === 'WIN' || $r === 'win') $byDate[$d]['wins']++;
            elseif ($r === 'LOSS' || $r === 'loss') $byDate[$d]['losses']++;
            elseif ($r === 'BE' || $r === 'be') $byDate[$d]['be']++;
            $byDate[$d]['pnl'] += (float)$e->getPnl();
        }

        $result = [];
        foreach ($byDate as $d) {
            $d['pnl'] = round($d['pnl'], 2);
            $d['win_rate'] = $d['trades'] > 0 ? round(($d['wins'] / $d['trades']) * 100, 1) : 0;
            $result[] = $d;
        }
        return $result;
    }

    /**
     * Get global Oraculo stats for all users (default 30 days).
     */
    public function getGlobalStats(int $days = 30): array
    {
        $to = new \DateTimeImmutable('now');
        $from = $to->modify("-{$days} days");

        // Top users by win rate
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT user_code, COUNT(*) as total, SUM(CASE WHEN result = 'WIN' THEN 1 ELSE 0 END) as wins, SUM(COALESCE(pnl, 0)) as pnl
             FROM journal_entries
             WHERE date >= ? AND date <= ?
             GROUP BY user_code
             ORDER BY pnl DESC
             LIMIT 10",
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        $top = array_map(function ($r) {
            $r['pnl'] = (float)$r['pnl'];
            $r['win_rate'] = $r['total'] > 0 ? round(($r['wins'] / $r['total']) * 100, 1) : 0;
            return $r;
        }, $rows);

        return [
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'top_users_by_pnl' => $top,
        ];
    }
}