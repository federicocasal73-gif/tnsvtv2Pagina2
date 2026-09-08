<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Task;
use App\Message\MarkTasksOverdueMessage;
use App\Service\NotificationService;
use App\Service\TaskCalendarSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * TNSVT Phase 3 — Marks tasks as overdue and notifies their assignees.
 *
 * Daily cron. Tasks whose due_date < now AND status is not yet 'approved'
 * get flipped to 'overdue'. For tasks that JUST became overdue, we send
 * a one-shot notification to the assignee so they don't miss it.
 */
#[AsMessageHandler]
final class MarkTasksOverdueHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notifier,
        private LoggerInterface $logger,
        private TaskCalendarSyncService $calendarSync,
    ) {}

    public function __invoke(MarkTasksOverdueMessage $message): void
    {
        $now = new \DateTimeImmutable();

        // Find tasks that just became overdue (status not overdue yet, but past due)
        $repo = $this->em->getRepository(Task::class);
        $qb = $repo->createQueryBuilder('t')
            ->where('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate < :now')
            ->andWhere('t.status NOT IN (:excluded)')
            ->andWhere('t.active = :active')
            ->setParameter('now', $now)
            ->setParameter('excluded', [Task::STATUS_APPROVED, Task::STATUS_OVERDUE])
            ->setParameter('active', true);

        $tasks = $qb->getQuery()->getResult();
        $count = 0;

        foreach ($tasks as $task) {
            $task->setStatus(Task::STATUS_OVERDUE);
            $task->touch();
            $count++;
            try { $this->calendarSync->onTaskStatusChanged($task); } catch (\Throwable) {}

            if ($task->getAssignedTo() && $task->getAssignedTo()->isActive()) {
                $this->notifier->notify(
                    $task->getAssignedTo(),
                    'task_overdue',
                    sprintf('Tarea vencida: %s', $task->getTitle()),
                    ['task_id' => (string) $task->getId()],
                    'task:' . $task->getId(),
                    true
                );
            }
            if ($task->getAssignedBy()) {
                $this->notifier->notify(
                    $task->getAssignedBy(),
                    'task_overdue',
                    sprintf('Una tarea que asignaste está vencida: %s', $task->getTitle()),
                    ['task_id' => (string) $task->getId()],
                    'task:' . $task->getId(),
                    false
                );
            }
        }

        $this->em->flush();
        $this->logger->info('[tasks] marked overdue', ['count' => $count]);
    }
}
