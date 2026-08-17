<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\NotificationDispatch;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;

/**
 * Fired daily by Symfony Scheduler.
 * Reminds admins to backup the production database and rotate secrets quarterly.
 */
final class DailyBackupReminderHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DailyBackupReminderMessage $message, SchedulerTransport $transport): void
    {
        $admins = $this->userRepository->createQueryBuilder('u')
            ->where('u.roles LIKE :admin')
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($admins as $admin) {
            $this->bus->dispatch(new NotificationDispatch(
                userId: $admin->getId(),
                type: 'admin_reminder',
                content: 'Recordatorio: backup diario de la base de datos',
                link: '/sanctum/admin',
            ));
            $count++;
        }

        if ($count > 0) {
            $this->logger->info('Sent daily backup reminders to admins', ['count' => $count]);
        }
    }
}