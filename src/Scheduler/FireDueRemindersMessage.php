<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * Triggered every minute by Symfony Scheduler.
 * Marks economic reminders as FIRED and queues notification pushes.
 */
final class FireDueRemindersMessage
{
}