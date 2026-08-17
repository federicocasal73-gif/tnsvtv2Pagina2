<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Message\NotificationDispatch;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NotificationHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private PushNotificationService $push,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotificationDispatch $message): void
    {
        $user = $this->userRepository->find($message->userId);
        if (!$user || !$user->isActive()) {
            $this->logger->warning('Notification target missing or inactive', [
                'user_id' => $message->userId,
            ]);
            return;
        }

        // Persist in-app notification
        $notif = new Notification();
        $notif->setUser($user);
        $notif->setType($message->type);
        $notif->setContent($message->content);
        $notif->setLink($message->link);
        $this->em->persist($notif);
        $this->em->flush();

        // Push notification via FCM (best-effort)
        try {
            $this->push->sendToUser(
                $user,
                $message->type,
                $message->content,
                $message->data + ['notification_id' => $notif->getId()],
                $message->link,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('FCM push failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}