<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByCode(string $code): ?User
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findByCodeLike(string $q): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.code LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('u.code', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }
}
