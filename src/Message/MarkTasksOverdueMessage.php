<?php

namespace App\Message;

/**
 * TNSVT Phase 3 — Scheduled marker for "mark all tasks overdue" job.
 *
 * Symfony Scheduler dispatches this message daily. The handler
 * (MarkTasksOverdueHandler) finds all tasks whose due_date is in the
 * past and that are not yet approved, and flips their status to overdue.
 *
 * Dispatch is wired up in src/Schedule.php.
 */
final class MarkTasksOverdueMessage
{
}
