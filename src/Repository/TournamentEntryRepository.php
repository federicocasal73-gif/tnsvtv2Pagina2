<?php

namespace App\Repository;

use App\Entity\TournamentEntry;
use App\Entity\Tournament;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TournamentEntry>
 */
class TournamentEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentEntry::class);
    }

    public function findUserEntry(Tournament $t, User $u): ?TournamentEntry
    {
        return $this->findOneBy(['tournament' => $t, 'user' => $u]);
    }

    /**
     * Leaderboard: todos los entries del torneo ordenados por pnl_pct DESC.
     * Si el torneo esta activo, muestra solo los activos.
     * Si esta finished/closed, muestra todos (para ver el resultado final).
     */
    public function getLeaderboard(Tournament $t): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.tournament = :t')
            ->setParameter('t', $t);

        if ($t->isActive()) {
            $qb->andWhere('e.status = :active')
               ->setParameter('active', TournamentEntry::STATUS_ACTIVE);
        }

        return $qb->orderBy('e.pnlPct', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getUserTournaments(User $u, bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->setParameter('user', $u)
            ->orderBy('e.joinedAt', 'DESC');
        if ($activeOnly) {
            $qb->andWhere('e.status = :status')
               ->setParameter('status', TournamentEntry::STATUS_ACTIVE);
        }
        return $qb->getQuery()->getResult();
    }
}
