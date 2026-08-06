<?php

namespace App\Repository;

use App\Entity\Clan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Clan::class);
    }

    public function findByTag(string $tag): ?Clan
    {
        return $this->findOneBy(['tag' => $tag]);
    }

    public function searchByName(string $query): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.name LIKE :query OR c.tag LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    public function getUserClan(int $userId): ?Clan
    {
        return $this->createQueryBuilder('c')
            ->join('c.members', 'm')
            ->where('m.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findTopClans(int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}