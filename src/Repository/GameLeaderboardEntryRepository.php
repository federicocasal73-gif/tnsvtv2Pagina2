<?php

namespace App\Repository;

use App\Entity\GameLeaderboardEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GameLeaderboardEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameLeaderboardEntry::class);
    }

    public function save(GameLeaderboardEntry $entry): void
    {
        $em = $this->getEntityManager();
        $now = new \DateTimeImmutable();

        if (!$entry->getId()) {
            $entry->setCreatedAt($now);
        }
        $entry->setUpdatedAt($now);

        $em->persist($entry);
        $em->flush();
    }

    public function getLeaderboard(
        string $type,
        string $period,
        int $limit = 100,
        int $offset = 0
    ): array {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('e', 'u.code', 'u.name', 'u.avatar')
           ->from(GameLeaderboardEntry::class, 'e')
           ->join('e.user', 'u')
           ->where('e.leaderboardType = :type')
           ->andWhere('e.period = :period')
           ->orderBy('e.score', 'DESC')
           ->setMaxResults($limit)
           ->setFirstResult($offset)
           ->setParameter('type', $type)
           ->setParameter('period', $period);

        return $qb->getQuery()->getResult();
    }

    public function getUserRank(
        int $userId,
        string $type,
        string $period
    ): ?int {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('COUNT(e.id) as rank')
           ->from(GameLeaderboardEntry::class, 'e')
           ->where('e.leaderboardType = :type')
           ->andWhere('e.period = :period')
           ->andWhere('e.score > (SELECT e2.score FROM App\Entity\GameLeaderboardEntry e2 WHERE e2.user = :userId AND e2.leaderboardType = :type AND e2.period = :period)')
           ->setParameter('type', $type)
           ->setParameter('period', $period)
           ->setParameter('userId', $userId);

        $result = $qb->getQuery()->getOneOrNullResult();
        return $result ? (int)$result['rank'] + 1 : null;
    }

    public function getTopPlayersByType(string $type, int $limit = 10): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('e', 'u.code', 'u.name', 'u.avatar')
           ->from(GameLeaderboardEntry::class, 'e')
           ->join('e.user', 'u')
           ->where('e.leaderboardType = :type')
           ->andWhere('e.period = :period')
           ->orderBy('e.score', 'DESC')
           ->setMaxResults($limit)
           ->setParameter('type', $type)
           ->setParameter('period', self::PERIOD_ALL_TIME);

        return $qb->getQuery()->getResult();
    }

    public function updateOrCreate(
        int $userId,
        string $type,
        string $period,
        int $score,
        ?string $seasonId = null
    ): GameLeaderboardEntry {
        $entry = $this->findOneBy([
            'user' => $userId,
            'leaderboardType' => $type,
            'period' => $period,
        ]);

        if (!$entry) {
            $user = $this->getEntityManager()->getReference(\App\Entity\User::class, $userId);
            $entry = new GameLeaderboardEntry();
            $entry->setUser($user);
            $entry->setLeaderboardType($type);
            $entry->setPeriod($period);
            $entry->setSeasonId($seasonId);
        }

        $entry->setScore($score);
        $this->save($entry);

        return $entry;
    }

    public function resetPeriod(string $period): void
    {
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder();
        $qb->delete(GameLeaderboardEntry::class, 'e')
           ->where('e.period = :period')
           ->setParameter('period', $period);
        $qb->getQuery()->execute();
    }
}