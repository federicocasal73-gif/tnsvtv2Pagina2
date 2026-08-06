<?php

namespace App\Repository;

use App\Entity\DailyChallengeEntry;
use App\Entity\DailyChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DailyChallengeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyChallengeEntry::class);
    }

    public function hasUserParticipated(DailyChallenge $challenge, int $userId): bool
    {
        $count = $this->count(['challenge' => $challenge, 'user' => $userId]);
        return $count > 0;
    }

    public function getChallengeLeaderboard(DailyChallenge $challenge, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.challenge = :challenge')
            ->setParameter('challenge', $challenge)
            ->orderBy('e.score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getUserBestScore(DailyChallenge $challenge, int $userId): ?DailyChallengeEntry
    {
        return $this->createQueryBuilder('e')
            ->where('e.challenge = :challenge')
            ->andWhere('e.user = :userId')
            ->setParameter('challenge', $challenge)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getUserRank(DailyChallenge $challenge, int $userId): ?int
    {
        $result = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) as rank')
            ->where('e.challenge = :challenge')
            ->andWhere('e.score > (SELECT e2.score FROM App\Entity\DailyChallengeEntry e2 WHERE e2.challenge = :challenge AND e2.user = :userId)')
            ->setParameter('challenge', $challenge)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? (int)$result['rank'] + 1 : null;
    }

    public function getTopPlayersThisWeek(): array
    {
        $weekAgo = new \DateTime('-7 days');
        
        return $this->createQueryBuilder('e')
            ->join('e.user', 'u')
            ->where('e.createdAt > :weekAgo')
            ->setParameter('weekAgo', $weekAgo)
            ->orderBy('e.score', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}