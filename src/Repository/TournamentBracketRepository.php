<?php

namespace App\Repository;

use App\Entity\TournamentBracket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TournamentBracketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentBracket::class);
    }

    public function findActive(): ?TournamentBracket
    {
        return $this->findOneBy(['status' => 'active'], ['startDate' => 'DESC']);
    }

    public function findUpcoming(): array
    {
        return $this->findBy(['status' => 'registration'], ['startDate' => 'ASC']);
    }

    public function findRecent(int $limit = 5): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}