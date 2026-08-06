<?php

namespace App\Repository;

use App\Entity\BracketMatch;
use App\Entity\TournamentBracket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BracketMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BracketMatch::class);
    }

    public function getMatchesForRound(TournamentBracket $tournament, int $round): array
    {
        return $this->findBy(
            ['tournament' => $tournament, 'round' => $round],
            ['matchIndex' => 'ASC']
        );
    }

    public function getActiveMatches(TournamentBracket $tournament): array
    {
        return $this->findBy(
            ['tournament' => $tournament, 'status' => 'active'],
            ['deadline' => 'ASC']
        );
    }

    public function getUserActiveMatch(TournamentBracket $tournament, int $userId): ?BracketMatch
    {
        return $this->createQueryBuilder('m')
            ->where('m.tournament = :tournament')
            ->andWhere('m.status = :status')
            ->andWhere('(m.player1 = :userId OR m.player2 = :userId)')
            ->setParameter('tournament', $tournament)
            ->setParameter('status', 'active')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getNextMatchForWinner(TournamentBracket $tournament, int $currentRound): ?BracketMatch
    {
        return $this->createQueryBuilder('m')
            ->where('m.tournament = :tournament')
            ->andWhere('m.round = :round')
            ->andWhere('m.status = :status')
            ->andWhere('m.player2 IS NULL')
            ->setParameter('tournament', $tournament)
            ->setParameter('round', $currentRound + 1)
            ->setParameter('status', 'pending')
            ->orderBy('m.matchIndex', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}