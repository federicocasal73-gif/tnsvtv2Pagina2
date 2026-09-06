<?php

namespace App;

use App\Message\MarkTasksOverdueMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // TNSVT Phase 3 — mark all tasks as overdue every 24h.
            // The handler updates status='overdue' on tasks with due_date < now
            // (excluding approved ones) and notifies the assignee + creator.
            ->add(
                RecurringMessage::every('24 hours', new MarkTasksOverdueMessage())
            )
        ;
    }
}
