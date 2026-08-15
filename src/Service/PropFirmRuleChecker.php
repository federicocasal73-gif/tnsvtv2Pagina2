<?php

namespace App\Service;

use App\Entity\JournalEntry;
use App\Entity\PropFirm;
use App\Entity\PropFirmAccount;
use App\Entity\PropFirmAlert;
use App\Entity\User;
use App\Repository\JournalEntryRepository;
use App\Repository\PropFirmAccountRepository;
use App\Repository\PropFirmAlertRepository;
use Doctrine\ORM\EntityManagerInterface;

class PropFirmRuleChecker
{
    public function __construct(
        private EntityManagerInterface $em,
        private JournalEntryRepository $journalEntryRepo,
        private PropFirmAccountRepository $pfaRepo,
        private PropFirmAlertRepository $alertRepo,
    ) {}

    /**
     * Evalúa un trade contra las reglas de la prop firm.
     * Retorna array de alertas generadas.
     *
     * @return PropFirmAlert[]
     */
    public function checkTrade(JournalEntry $entry, PropFirmAccount $account): array
    {
        $alerts = [];

        $this->updateAccount($account, $entry);

        if ($account->getStatus() !== PropFirmAccount::STATUS_ACTIVE) {
            return $alerts;
        }

        $firm = $account->getPropFirm();
        if (!$firm) return $alerts;

        $drawdownPct = $account->getDrawdownPct();
        $profitPct = $account->getProfitPct();
        $dailyLossPct = $this->getDailyLossPct($account->getUser(), $entry);
        $user = $account->getUser();

        $maxDrawdown = (float) $firm->getRule('max_drawdown_pct', 10);
        $maxDailyLoss = (float) $firm->getRule('max_daily_loss_pct', 5);
        $profitTarget = (float) $firm->getRule('profit_target_pct', 10);

        // Drawdown warnings
        if ($drawdownPct >= $maxDrawdown * 0.9 && $drawdownPct < $maxDrawdown) {
            $alerts[] = $this->createAlert(
                $user, $account,
                PropFirmAlert::TYPE_DRAWDOWN_WARNING,
                PropFirmAlert::SEVERITY_WARNING,
                sprintf('Drawdown al %.1f%% — muy cerca del límite de %.0f%%', $drawdownPct, $maxDrawdown)
            );
        }

        if ($drawdownPct >= $maxDrawdown) {
            $account->setStatus(PropFirmAccount::STATUS_VIOLATED);
            $alerts[] = $this->createAlert(
                $user, $account,
                PropFirmAlert::TYPE_DRAWDOWN_CRITICAL,
                PropFirmAlert::SEVERITY_DANGER,
                sprintf('VIOLACIÓN: Drawdown de %.1f%% excede el máximo de %.0f%%', $drawdownPct, $maxDrawdown)
            );
        }

        // Daily loss warning
        if ($dailyLossPct >= $maxDailyLoss * 0.9 && $dailyLossPct < $maxDailyLoss) {
            $alerts[] = $this->createAlert(
                $user, $account,
                PropFirmAlert::TYPE_DAILY_LOSS_WARNING,
                PropFirmAlert::SEVERITY_WARNING,
                sprintf('Pérdida diaria de %.1f%% — cuidado con el límite de %.0f%%', $dailyLossPct, $maxDailyLoss)
            );
        }

        if ($dailyLossPct >= $maxDailyLoss) {
            $account->setStatus(PropFirmAccount::STATUS_VIOLATED);
            $alerts[] = $this->createAlert(
                $user, $account,
                PropFirmAlert::TYPE_VIOLATION,
                PropFirmAlert::SEVERITY_DANGER,
                sprintf('VIOLACIÓN: Pérdida diaria de %.1f%% excede el máximo de %.0f%%', $dailyLossPct, $maxDailyLoss)
            );
        }

        // Profit target achieved
        if ($profitPct >= $profitTarget) {
            $account->setStatus(PropFirmAccount::STATUS_PASSED);
            $alerts[] = $this->createAlert(
                $user, $account,
                PropFirmAlert::TYPE_PROFIT_ACHIEVED,
                PropFirmAlert::SEVERITY_INFO,
                sprintf('Objetivo de profit alcanzado: %.1f%% (meta: %.0f%%)', $profitPct, $profitTarget)
            );
        }

        $this->em->flush();
        return $alerts;
    }

    public function updateAccount(PropFirmAccount $account, JournalEntry $entry): void
    {
        $balance = (float) ($entry->getPnl() ?? 0);
        if ($balance === 0.0) return;

        $currentBalance = (float) $account->getCurrentBalance();
        $peakBalance = (float) $account->getPeakBalance();
        $newBalance = $currentBalance + $balance;

        $account->setCurrentBalance(number_format($newBalance, 2, '.', ''));

        if ($newBalance > $peakBalance) {
            $account->setPeakBalance(number_format($newBalance, 2, '.', ''));
        }
    }

    public function getStatus(PropFirmAccount $account): array
    {
        $firm = $account->getPropFirm();
        $size = (float) $account->getAccountSize();

        return [
            'id' => $account->getId(),
            'prop_firm' => $firm ? $firm->getName() : null,
            'account_size' => $size,
            'peak_balance' => (float) $account->getPeakBalance(),
            'current_balance' => (float) $account->getCurrentBalance(),
            'drawdown_pct' => $account->getDrawdownPct(),
            'profit_pct' => $account->getProfitPct(),
            'daily_loss_pct' => $this->getDailyLossPctForUser($account->getUser()),
            'max_drawdown_pct' => $firm ? (float) $firm->getRule('max_drawdown_pct', 10) : null,
            'max_daily_loss_pct' => $firm ? (float) $firm->getRule('max_daily_loss_pct', 5) : null,
            'profit_target_pct' => $firm ? (float) $firm->getRule('profit_target_pct', 10) : null,
            'status' => $account->getStatus(),
        ];
    }

    /**
     * Daily loss as percent of account size, computed from today's journal entries.
     * Public so it can be reused by GuardianSignalCollector and the API.
     */
    public function getDailyLossPctForUser(User $user): float
    {
        $todayStart = (new \DateTimeImmutable())->setTime(0, 0, 0);
        $todayEnd = $todayStart->modify('+1 day');

        $entries = $this->journalEntryRepo->createQueryBuilder('j')
            ->where('j.userCode = :uc')
            ->andWhere('j.createdAt >= :start')
            ->andWhere('j.createdAt < :end')
            ->setParameter('uc', $user->getCode())
            ->setParameter('start', $todayStart)
            ->setParameter('end', $todayEnd)
            ->getQuery()
            ->getResult();

        $todayLoss = 0.0;
        foreach ($entries as $e) {
            $pnl = (float) ($e->getPnl() ?? 0);
            if ($pnl < 0) $todayLoss += abs($pnl);
        }

        $size = (float) ($this->getAccountSize($user));
        return $size > 0 ? round($todayLoss / $size * 100, 2) : 0;
    }

    private function getDailyLossPct(User $user, JournalEntry $currentEntry): float
    {
        return $this->getDailyLossPctForUser($user);
    }

    private function getAccountSize(User $user): float
    {
        $accounts = $this->pfaRepo->findActiveByUser($user);
        if (empty($accounts)) return 10000;
        return (float) $accounts[0]->getAccountSize();
    }

    private function createAlert(
        User $user,
        PropFirmAccount $account,
        string $type,
        string $severity,
        string $message,
    ): PropFirmAlert {
        $alert = new PropFirmAlert();
        $alert->setUser($user);
        $alert->setPropFirmAccount($account);
        $alert->setAlertType($type);
        $alert->setMessage($message);
        $alert->setSeverity($severity);
        $this->em->persist($alert);
        return $alert;
    }
}
