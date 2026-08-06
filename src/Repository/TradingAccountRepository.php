<?php

namespace App\Repository;

use App\Entity\TradingAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TradingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradingAccount::class);
    }

    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :user')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByNameAndUser(User $user, string $name): ?TradingAccount
    {
        return $this->findOneBy([
            'user' => $user,
            'name' => $name,
            'deletedAt' => null,
        ]);
    }

    public function findSoftDeleted(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.deletedAt IS NOT NULL')
            ->orderBy('a.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
