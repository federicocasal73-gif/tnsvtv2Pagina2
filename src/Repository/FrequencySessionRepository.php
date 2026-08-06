<?php

namespace App\Repository;

use App\Entity\FrequencySession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FrequencySession>
 */
class FrequencySessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FrequencySession::class);
    }

    /** @return FrequencySession[] */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :u AND s.completed = false')
            ->setParameter('u', $user)
            ->orderBy('s.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalMinutesForUser(User $user): int
    {
        return (int)$this->createQueryBuilder('s')
            ->select('SUM(s.durationMinutes) AS total')
            ->where('s.user = :u AND s.completed = true')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}