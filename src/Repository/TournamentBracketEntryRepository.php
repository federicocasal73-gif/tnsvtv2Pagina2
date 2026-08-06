<?php

namespace App\Repository;

use App\Entity\TournamentBracketEntry;
use App\Entity\TournamentBracket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TournamentBracketEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentBracketEntry::class);
    }

    public function isUserRegistered(TournamentBracket $tournament, int $userId): bool
    {
        $count = $this->count(['tournament' => $tournament, 'user' => $userId]);
        return $count > 0;
    }

    public function getRegisteredCount(TournamentBracket $tournament): int
    {
        return $this->count(['tournament' => $tournament]);
    }

    public function getRegisteredUsers(TournamentBracket $tournament): array
    {
        return $this->findBy(['tournament' => $tournament], ['joinedAt' => 'ASC']);
    }

    public function getLeaderboard(TournamentBracket $tournament): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.tournament = :tournament')
            ->andWhere('e.eliminated = false')
            ->orderBy('e.joinedAt', 'ASC')
            ->setParameter('tournament', $tournament)
            ->getQuery()
            ->getResult();
    }
}