<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\EconomicReminder;
use App\Entity\Notification;
use App\Message\NotificationDispatch;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;

/**
 * Fired every minute by Symfony Scheduler.
 * Marks economic reminders as FIRED and queues push notifications for users.
 */
final class FireDueRemindersHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FireDueRemindersMessage $message, SchedulerTransport $transport): void
    {
        $now = new \DateTimeImmutable();
        $due = $this->em->getRepository(EconomicReminder::class)
            ->createQueryBuilder('r')
            ->where('r.status = :pending')
            ->andWhere('r.remindAt <= :now')
            ->setParameter('pending', EconomicReminder::STATUS_PENDING)
            ->setParameter('now', $now)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($due as $reminder) {
            $reminder->markFired();
            $count++;

            // Queue async notification to user
            $this->bus->dispatch(new NotificationDispatch(
                userId: $reminder->getUser()->getId(),
                type: 'economic_alert',
                content: sprintf('%s a las %s', $reminder->getEventTitle(), $reminder->getEventTime()),
                link: '/calendar',
                data: ['reminder_id' => $reminder->getId()],
            ));
        }

        $this->em->flush();

        if ($count > 0) {
            $this->logger->info('Fired economic reminders', ['count' => $count]);
        }
    }
}