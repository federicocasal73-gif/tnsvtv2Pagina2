<?php

namespace App\Repository;

use App\Entity\SpecialEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SpecialEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpecialEvent::class);
    }

    public function findActive(): ?SpecialEvent
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->setParameter('status', SpecialEvent::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->setParameter('status', SpecialEvent::STATUS_UPCOMING)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.startDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function activatePending(): int
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('e')
            ->update()
            ->set('e.status', SpecialEvent::STATUS_ACTIVE)
            ->where('e.status = :upcoming')
            ->andWhere('e.startDate <= :now')
            ->setParameter('upcoming', SpecialEvent::STATUS_UPCOMING)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }

    public function finishExpired(): int
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('e')
            ->update()
            ->set('e.status', SpecialEvent::STATUS_FINISHED)
            ->where('e.status = :active')
            ->andWhere('e.endDate < :now')
            ->setParameter('active', SpecialEvent::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}