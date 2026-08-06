<?php

namespace App\Repository;

use App\Entity\Duel;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Duel>
 */
class DuelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Duel::class);
    }

    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('(d.player1 = :user OR d.player2 = :user)')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [Duel::STATUS_WAITING, Duel::STATUS_ACTIVE])
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByCode(string $code): ?Duel
    {
        return $this->findOneBy(['code' => $code]);
    }
}
