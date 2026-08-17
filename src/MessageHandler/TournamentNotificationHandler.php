<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\TournamentNotification;
use App\Repository\TournamentRepository;
use App\Repository\UserRepository;
use App\Service\PushNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TournamentNotificationHandler
{
    public function __construct(
        private TournamentRepository $tournamentRepository,
        private UserRepository $userRepository,
        private PushNotificationService $push,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TournamentNotification $message): void
    {
        $tournament = $this->tournamentRepository->find($message->tournamentId);
        if (!$tournament) {
            return;
        }

        // Notify all active users (broadcast)
        $users = $this->userRepository->findBy(['active' => true]);
        foreach ($users as $user) {
            try {
                $this->push->sendToUser(
                    $user,
                    'tournament_' . $message->event,
                    sprintf('Torneo %s: %s', $tournament->getName(), $message->event),
                    ['tournament_id' => $tournament->getId()],
                    $message->link ?? '/tournaments',
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Tournament push failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}