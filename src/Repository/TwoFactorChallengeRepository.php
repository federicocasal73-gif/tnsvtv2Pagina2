<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TwoFactorChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TwoFactorChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TwoFactorChallenge::class);
    }

    public function findActiveForUser(int $userId, string $purpose): ?TwoFactorChallenge
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('user', $userId)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
