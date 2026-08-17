<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Centralizes Mercure publishing for chat, notifications, and tournament events.
 * Topic format:
 *   - chat/{conversation_id} — participants subscribe to their conversations
 *   - user/{user_id}/notifications — user-specific notification stream
 *   - tournament/{tournament_id} — tournament updates (leaderboard, brackets)
 */
class MercurePublisher
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function publishChatMessage(int $conversationId, array $payload): void
    {
        $this->hub->publish(new Update(
            sprintf('/chat/%d', $conversationId),
            json_encode($payload, JSON_THROW_ON_ERROR),
        ));
    }

    public function publishUserNotification(User $user, array $payload): void
    {
        $this->hub->publish(new Update(
            sprintf('/user/%d/notifications', $user->getId()),
            json_encode($payload, JSON_THROW_ON_ERROR),
            true, // private — only the user can subscribe
        ));
    }

    public function publishTournamentUpdate(int $tournamentId, array $payload): void
    {
        $this->hub->publish(new Update(
            sprintf('/tournament/%d', $tournamentId),
            json_encode($payload, JSON_THROW_ON_ERROR),
        ));
    }

    public function publishBroadcast(string $topic, array $payload): void
    {
        $this->hub->publish(new Update(
            $topic,
            json_encode($payload, JSON_THROW_ON_ERROR),
        ));
    }
}