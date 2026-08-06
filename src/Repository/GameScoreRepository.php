<?php

namespace App\Repository;

use App\Entity\GameScore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameScore>
 */
class GameScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameScore::class);
    }

    public function findRecentForUser(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->setParameter('user', $userId)
            ->orderBy('g.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getBestScoreForUser(int $userId, string $mode): ?int
    {
        $result = $this->createQueryBuilder('g')
            ->select('MAX(g.score) as best')
            ->where('g.user = :user')
            ->andWhere('g.mode = :mode')
            ->setParameter('user', $userId)
            ->setParameter('mode', $mode)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (int) $result : null;
    }

    public function getTotalXpForUser(int $userId): int
    {
        $result = $this->createQueryBuilder('g')
            ->select('SUM(g.xpGained) as total')
            ->where('g.user = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (int) $result : 0;
    }
}
