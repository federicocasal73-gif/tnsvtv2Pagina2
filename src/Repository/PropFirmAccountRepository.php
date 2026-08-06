<?php

namespace App\Repository;

use App\Entity\PropFirmAccount;
use App\Entity\TradingAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PropFirmAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropFirmAccount::class);
    }

    public function findActiveByUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'status' => PropFirmAccount::STATUS_ACTIVE],
            ['createdAt' => 'DESC']
        );
    }

    public function findByUserAndAccount(User $user, TradingAccount $account): ?PropFirmAccount
    {
        return $this->findOneBy(['user' => $user, 'tradingAccount' => $account]);
    }

    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', PropFirmAccount::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
