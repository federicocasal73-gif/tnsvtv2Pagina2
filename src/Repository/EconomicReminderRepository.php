<?php

namespace App\Repository;

use App\Entity\EconomicReminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EconomicReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EconomicReminder::class);
    }

    public function findPendingForUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'status' => EconomicReminder::STATUS_PENDING],
            ['remindAt' => 'ASC']
        );
    }

    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC'], 50);
    }

    public function findDueForFiring(\DateTimeImmutable $now, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.remindAt <= :now')
            ->setParameter('status', EconomicReminder::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('r.remindAt', 'ASC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function findExisting(User $user, string $eventDate, string $eventTime): ?EconomicReminder
    {
        return $this->findOneBy([
            'user' => $user,
            'eventDate' => $eventDate,
            'eventTime' => $eventTime,
            'status' => EconomicReminder::STATUS_PENDING,
        ]);
    }
}
