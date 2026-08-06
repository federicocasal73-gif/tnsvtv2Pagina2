<?php

namespace App\Repository;

use App\Entity\EventMission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventMissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventMission::class);
    }

    public function findByEvent(int $eventId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.event = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('m.difficulty', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWithType(int $eventId, string $type): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.event = :eventId')
            ->andWhere('m.type = :type')
            ->setParameter('eventId', $eventId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult();
    }
}