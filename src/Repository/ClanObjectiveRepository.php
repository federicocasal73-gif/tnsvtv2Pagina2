<?php

namespace App\Repository;

use App\Entity\ClanObjective;
use App\Entity\Clan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClanObjectiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClanObjective::class);
    }

    public function getActiveObjectives(Clan $clan): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.clan = :clan')
            ->andWhere('o.completed = false')
            ->andWhere('o.expiresAt > :now OR o.expiresAt IS NULL')
            ->setParameter('clan', $clan)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('o.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getCompletedObjectives(Clan $clan, int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.clan = :clan')
            ->andWhere('o.completed = true')
            ->setParameter('clan', $clan)
            ->orderBy('o.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getClanTotalRewards(Clan $clan): array
    {
        $result = $this->createQueryBuilder('o')
            ->select('SUM(JSON_EXTRACT(o.rewards, "$.coins")) as totalCoins, SUM(JSON_EXTRACT(o.rewards, "$.reputation")) as totalRep')
            ->where('o.clan = :clan')
            ->andWhere('o.completed = true')
            ->setParameter('clan', $clan)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'coins' => (int) ($result['totalCoins'] ?? 0),
            'reputation' => (int) ($result['totalRep'] ?? 0),
        ];
    }
}