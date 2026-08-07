<?php

namespace App\Repository;

use App\Entity\TradeSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeSnapshot>
 */
class TradeSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeSnapshot::class);
    }

    /** @return TradeSnapshot[] */
    public function findByUserRange(string $userCode, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.userCode = :u')
            ->andWhere('s.snapshotDate >= :from')
            ->andWhere('s.snapshotDate <= :to')
            ->setParameter('u', $userCode)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('s.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getByUserAndDate(string $userCode, string $date): ?TradeSnapshot
    {
        return $this->findOneBy(['userCode' => $userCode, 'snapshotDate' => new \DateTimeImmutable($date)]);
    }
}