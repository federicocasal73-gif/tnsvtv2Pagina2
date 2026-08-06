<?php

namespace App\Repository;

use App\Entity\ShopItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ShopItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopItem::class);
    }

    public function findActive(?string $category = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.active = :a')
            ->setParameter('a', true)
            ->orderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.coinCost', 'ASC');
        if ($category) {
            $qb->andWhere('i.category = :c')->setParameter('c', $category);
        }
        return $qb->getQuery()->getResult();
    }

    public function userHasPurchased(User $user, string $itemId): bool
    {
        $count = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(\App\Entity\ShopPurchase::class, 'p')
            ->where('p.user = :u')
            ->andWhere('p.itemId = :i')
            ->setParameter('u', $user)
            ->setParameter('i', $itemId)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    public function findUserOwnedIds(User $user): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('DISTINCT p.itemId')
            ->from(\App\Entity\ShopPurchase::class, 'p')
            ->where('p.user = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getScalarResult();
        return array_column($rows, 'itemId');
    }
}
