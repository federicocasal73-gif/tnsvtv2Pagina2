<?php

namespace App\Repository;

use App\Entity\Tournament;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tournament>
 */
class TournamentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tournament::class);
    }

    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :active')
            ->andWhere('t.endDate > :now')
            ->setParameter('active', Tournament::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findExpiredActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :active')
            ->andWhere('t.endDate <= :now')
            ->setParameter('active', Tournament::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function findFinished(int $limit = 20): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->setParameter('statuses', [Tournament::STATUS_FINISHED, Tournament::STATUS_CANCELLED])
            ->orderBy('t.finishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
