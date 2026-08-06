<?php

namespace App\Repository;

use App\Entity\WalletTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WalletTransaction>
 */
class WalletTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalletTransaction::class);
    }

    public function findRecentForUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByPaymentId(string $paymentId): ?WalletTransaction
    {
        return $this->findOneBy(['refPaymentId' => $paymentId]);
    }

    public function findPendingWithdraws(int $limit = 50): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.type = :type')
            ->andWhere('w.status = :status')
            ->setParameter('type', WalletTransaction::TYPE_WITHDRAW)
            ->setParameter('status', WalletTransaction::STATUS_PENDING)
            ->orderBy('w.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getTotalByType(User $user, string $type): float
    {
        $result = $this->createQueryBuilder('w')
            ->select('SUM(w.amount) as total')
            ->where('w.user = :user')
            ->andWhere('w.type = :type')
            ->andWhere('w.status = :status')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('status', WalletTransaction::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();
        return (float) ($result ?? 0);
    }
}
