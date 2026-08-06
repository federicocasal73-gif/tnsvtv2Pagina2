<?php

namespace App\Repository;

use App\Entity\PropFirmAlert;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PropFirmAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropFirmAlert::class);
    }

    public function findUnreadByUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'isRead' => false],
            ['createdAt' => 'DESC']
        );
    }

    public function findRecentByUser(User $user, int $limit = 20): array
    {
        return $this->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            $limit
        );
    }

    public function countUnreadByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :user')
            ->andWhere('a.isRead = :read')
            ->setParameter('user', $user)
            ->setParameter('read', false)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
