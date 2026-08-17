<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Repository\EconomicReminderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Recurring background tasks for TNSVT Reino v2.
 *
 * To run: `bin/console messenger:consume scheduler_<name> --time-limit=60`
 * In production, host this via systemd / supervisord / cron.
 */
#[AsSchedule('main')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private EconomicReminderRepository $reminderRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule()
            ->add(RecurringMessage::every('1 minute', new FireDueRemindersMessage()))
            ->add(RecurringMessage::every('5 minutes', new RecomputeLeaderboardsMessage()))
            ->add(RecurringMessage::every('1 hour', new PurgeExpiredTokensMessage()))
            ->add(RecurringMessage::every('1 day', new DailyBackupReminderMessage()));

        return $schedule;
    }
}