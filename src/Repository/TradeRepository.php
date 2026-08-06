<?php

namespace App\Repository;

use App\Entity\Trade;
use App\Entity\Tournament;
use App\Entity\TournamentEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trade>
 */
class TradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trade::class);
    }

    /** @return Trade[] */
    public function findRecentForEntry(TournamentEntry $entry, int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.entry = :entry')
            ->setParameter('entry', $entry)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return Trade[] */
    public function findForUserTournament(User $user, Tournament $tournament, int $limit = 100): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.tournament = :t')
            ->setParameter('user', $user)
            ->setParameter('t', $tournament)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Cuenta trades resueltos en los ultimos N segundos para anti-spam. */
    public function countRecentForUser(User $user, int $sinceSeconds = 60): int
    {
        $since = (new \DateTimeImmutable())->modify("-{$sinceSeconds} seconds");
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :user')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}