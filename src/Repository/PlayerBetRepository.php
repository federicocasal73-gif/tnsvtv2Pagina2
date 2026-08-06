<?php

namespace App\Repository;

use App\Entity\PlayerBet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlayerBetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerBet::class);
    }

    public function getUserPendingBets(int $userId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.challenger = :userId OR b.opponent = :userId')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('userId', $userId)
            ->setParameter('statuses', ['pending', 'accepted'])
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getUserBetHistory(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.challenger = :userId OR b.opponent = :userId')
            ->andWhere('b.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'completed')
            ->orderBy('b.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getOpenChallenges(int $limit = 20): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.opponent IS NULL')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.amount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getPendingChallengesForUser(int $userId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.opponent = :userId')
            ->andWhere('b.status = :status')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getUserStats(int $userId): array
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = "
            SELECT 
                COUNT(*) as total_bets,
                SUM(CASE WHEN winner_id = :userId THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN winner_id IS NOT NULL AND winner_id != :userId THEN 1 ELSE 0 END) as losses,
                SUM(CASE WHEN winner_id = :userId THEN total_pot ELSE 0 END) as total_won,
                SUM(CASE WHEN winner_id != :userId AND winner_id IS NOT NULL THEN total_pot ELSE 0 END) as total_lost
            FROM player_bets
            WHERE (challenger_id = :userId OR opponent_id = :userId)
            AND status = 'completed'
        ";
        
        $result = $conn->fetchAssociative($sql, ['userId' => $userId]);
        
        return [
            'totalBets' => (int) ($result['total_bets'] ?? 0),
            'wins' => (int) ($result['wins'] ?? 0),
            'losses' => (int) ($result['losses'] ?? 0),
            'winRate' => $result['total_bets'] > 0 
                ? round(($result['wins'] / $result['total_bets']) * 100, 1) 
                : 0,
            'totalWon' => (int) ($result['total_won'] ?? 0),
            'totalLost' => (int) ($result['total_lost'] ?? 0),
            'netProfit' => (int) (($result['total_won'] ?? 0) - ($result['total_lost'] ?? 0)),
        ];
    }
}