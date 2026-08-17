<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message for tournament lifecycle notifications (created, started, finished, cancelled).
 * Handled by TournamentNotificationHandler.
 */
final class TournamentNotification
{
    public function __construct(
        public readonly int $tournamentId,
        public readonly string $event,
        public readonly ?string $link = null,
    ) {
    }
}