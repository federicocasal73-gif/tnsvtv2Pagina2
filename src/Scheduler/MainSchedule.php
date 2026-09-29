<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\MarkTasksOverdueMessage;
use App\Repository\EconomicReminderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring background tasks for TNSVT Reino v2.
 *
 * Consolidates ALL scheduled jobs under the 'main' transport so
 * operators only need to consume one queue (`scheduler_main`) instead
 * of the legacy pair `scheduler_default` + `scheduler_main`.
 *
 * State: the scheduler is `stateful` (uses the injected cache to
 * remember the last time each recurring message ran) and runs
 * `processOnlyLastMissedRun` (if the worker was down for an entire
 * interval, only run the most recent missed iteration, not all of
 * them). Both flags preserve the behaviour of the previous
 * `App\Schedule` provider.
 *
 * To run: `bin/console messenger:consume scheduler_main --time-limit=60`
 * In production, host this via supervisord / cron (see AGENTS.md
 * §Database backups for Hostinger constraints).
 */
#[AsSchedule('main')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private EconomicReminderRepository $reminderRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 minute', new FireDueRemindersMessage()))
            ->add(RecurringMessage::every('1 hour', new PurgeExpiredTokensMessage()))
            ->add(RecurringMessage::every('1 day', new DailyBackupReminderMessage()))
            // TNSVT Phase 3 — mark overdue tasks every 24h.
            // Migrated from App\Schedule (legacy provider) to keep a
            // single source of truth for scheduled jobs.
            ->add(RecurringMessage::every('24 hours', new MarkTasksOverdueMessage()));
    }
}
