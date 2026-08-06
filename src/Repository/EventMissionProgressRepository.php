<?php

namespace App\Repository;

use App\Entity\EventMissionProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventMissionProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventMissionProgress::class);
    }

    public function findUserProgress(int $eventId, int $userId): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.mission', 'm')
            ->where('m.event = :eventId')
            ->andWhere('p.user = :userId')
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function findOrCreateUserProgress(int $missionId, int $userId): EventMissionProgress
    {
        $existing = $this->createQueryBuilder('p')
            ->where('p.mission = :missionId')
            ->andWhere('p.user = :userId')
            ->setParameter('missionId', $missionId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $existing;
    }

    public function countCompleted(int $missionId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.mission = :missionId')
            ->andWhere('p.completed = :completed')
            ->setParameter('missionId', $missionId)
            ->setParameter('completed', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}