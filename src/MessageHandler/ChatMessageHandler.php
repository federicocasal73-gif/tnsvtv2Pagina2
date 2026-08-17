<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ChatMessageSent;
use App\Repository\ConversationParticipantRepository;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ChatMessageHandler
{
    public function __construct(
        private ConversationParticipantRepository $participants,
        private UserRepository $userRepository,
        private PushNotificationService $push,
        private MercurePublisher $publisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChatMessageSent $message): void
    {
        $participants = $this->participants->findByConversation($message->conversationId);
        $sender = $this->userRepository->find($message->senderId);
        if (!$sender) {
            $this->logger->warning('Chat message from non-existent user', [
                'message_id' => $message->messageId,
                'sender_id' => $message->senderId,
            ]);
            return;
        }

        // Real-time push via Mercure SSE: all participants with active subscriptions get the message instantly
        try {
            $this->publisher->publishChatMessage($message->conversationId, [
                'id' => $message->messageId,
                'conversation_id' => $message->conversationId,
                'sender_code' => $sender->getCode(),
                'sender_name' => $sender->getName(),
                'sent_at' => time(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed (will fall back to FCM)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Background FCM push for users who aren't actively subscribed
        foreach ($participants as $participant) {
            if ($participant->getUser()?->getId() === $sender->getId()) {
                continue; // skip sender
            }
            try {
                $this->push->sendToUser(
                    $participant->getUser(),
                    'dm',
                    'Nuevo mensaje de ' . $sender->getName(),
                    ['message_id' => $message->messageId, 'conversation_id' => $message->conversationId],
                    '/chat',
                );
            } catch (\Throwable $e) {
                $this->logger->error('Push notification failed', [
                    'participant' => $participant->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}