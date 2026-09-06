<?php

namespace App\Service;

use App\Entity\CalendarEvent;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * TNSVT Phase 6 — keeps Task entities and CalendarEvent entries in sync.
 *
 * Soft-link strategy: each Task has 0..1 CalendarEvent whose `meeting_url`
 * is set to `task:<id>`. We look up the existing event by that marker and
 * update it instead of creating duplicates when the Task changes.
 *
 *   onTaskSaved(task)         → create or update linked CalendarEvent
 *   onTaskStatusChanged(task) → mark CalendarEvent as STATUS_DONE if
 *                               task is approved; delete if task removed
 */
class TaskCalendarSyncService
{
    public const LINK_PREFIX = 'task:';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create or update the linked CalendarEvent for the given task.
     * No-op if the task has no due_date.
     */
    public function onTaskSaved(Task $task): ?CalendarEvent
    {
        if (!$task->getDueDate()) {
            // No deadline → if an event already exists, leave it (it's not
            // tied to the deadline). We could optionally delete it, but
            // that's surprising for the admin.
            return null;
        }

        $event = $this->findLinkedEvent($task);
        if (!$event) {
            $event = new CalendarEvent();
            $event->setType(CalendarEvent::TYPE_TASK);
            $event->setOwner($task->getAssignedBy());
        }

        $event->setTitle('[Tarea] ' . $task->getTitle());
        $event->setDescription($this->buildDescription($task));
        $event->setStartsAt($task->getDueDate());
        // endsAt = due_date + 1 hour (estimated work block)
        $event->setEndsAt($task->getDueDate()->modify('+1 hour'));
        $event->setMentor($task->getAssignedBy());
        $event->setMeetingUrl(self::LINK_PREFIX . $task->getId());
        $event->setStatus($task->getStatus() === Task::STATUS_APPROVED
            ? CalendarEvent::STATUS_DONE
            : CalendarEvent::STATUS_SCHEDULED);
        $event->setColor($this->colorForPriority($task->getPriority()));

        $this->em->persist($event);
        $this->em->flush();

        $this->logger->info('[task-cal] synced event', [
            'task_id' => $task->getId(),
            'event_id' => $event->getId(),
            'status' => $event->getStatus(),
        ]);
        return $event;
    }

    /**
     * Update event status when the Task status changes.
     */
    public function onTaskStatusChanged(Task $task): void
    {
        $event = $this->findLinkedEvent($task);
        if (!$event) return;
        $newStatus = match ($task->getStatus()) {
            Task::STATUS_APPROVED => CalendarEvent::STATUS_DONE,
            Task::STATUS_IN_PROGRESS => CalendarEvent::STATUS_LIVE,
            default => CalendarEvent::STATUS_SCHEDULED,
        };
        $event->setStatus($newStatus);
        $this->em->flush();
    }

    /**
     * Remove the linked CalendarEvent when a Task is deleted.
     */
    public function onTaskDeleted(int $taskId): void
    {
        $event = $this->em->getRepository(CalendarEvent::class)->findOneBy([
            'meetingUrl' => self::LINK_PREFIX . $taskId,
        ]);
        if ($event) {
            $this->em->remove($event);
            $this->em->flush();
        }
    }

    private function findLinkedEvent(Task $task): ?CalendarEvent
    {
        return $this->em->getRepository(CalendarEvent::class)->findOneBy([
            'meetingUrl' => self::LINK_PREFIX . $task->getId(),
        ]);
    }

    private function buildDescription(Task $task): string
    {
        $lines = [];
        if ($task->getDescription()) {
            $lines[] = $task->getDescription();
        }
        if ($task->getPriority() && $task->getPriority() !== Task::PRIORITY_NORMAL) {
            $lines[] = 'Prioridad: ' . $task->getPriorityLabel();
        }
        if ($task->getEstimatedMinutes()) {
            $lines[] = 'Estimado: ' . $task->getEstimatedMinutes() . ' min';
        }
        if ($task->getInstructions()) {
            $lines[] = "\nInstrucciones:\n" . $task->getInstructions();
        }
        $lines[] = "\n[Actualizado: " . (new \DateTimeImmutable())->format('c') . ']';
        return implode("\n\n", $lines);
    }

    private function colorForPriority(string $priority): ?string
    {
        return match ($priority) {
            Task::PRIORITY_URGENT => '#dc2626',
            Task::PRIORITY_HIGH => '#fb923c',
            Task::PRIORITY_NORMAL => null,
            Task::PRIORITY_LOW => '#38bdf8',
            default => null,
        };
    }
}
