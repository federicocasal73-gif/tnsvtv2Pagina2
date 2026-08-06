<?php

namespace App\Repository;

use App\Entity\MarketCandle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketCandleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketCandle::class);
    }

    public function findLatest(string $symbol, string $exchange, string $interval, int $limit = 200): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.symbol = :symbol')
            ->andWhere('c.exchange = :exchange')
            ->andWhere('c.interval = :interval')
            ->setParameter('symbol', $symbol)
            ->setParameter('exchange', $exchange)
            ->setParameter('interval', $interval)
            ->orderBy('c.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function upsertCandle(MarketCandle $candle): void
    {
        $this->getEntityManager()->merge($candle);
    }
}