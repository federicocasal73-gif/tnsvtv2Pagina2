<?php

namespace App\Repository;

use App\Entity\FeedPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FeedPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedPost::class);
    }

    public function findLatest(int $limit = 50, ?string $category = null, int $offset = 0, ?int $beforeId = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(50, $limit)));

        if ($category && $category !== 'all') {
            $qb->andWhere('p.category = :category')
               ->setParameter('category', $category);
        }
        if ($beforeId) {
            $qb->andWhere('p.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }
}
