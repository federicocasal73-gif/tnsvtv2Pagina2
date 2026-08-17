<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message sent when a chat message is dispatched.
 * Handled by ChatMessageHandler which pushes the notification to recipients.
 */
final class ChatMessageSent
{
    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly int $senderId,
    ) {
    }
}