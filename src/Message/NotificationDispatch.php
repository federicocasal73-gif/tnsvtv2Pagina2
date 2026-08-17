<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message for delivering notifications to one or more users.
 * Handled by NotificationHandler which inserts Notification entities + pushes via FCM.
 */
final class NotificationDispatch
{
    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly string $content,
        public readonly ?string $link = null,
        public readonly array $data = [],
    ) {
    }
}