<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserFrequency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserFrequency>
 */
class UserFrequencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserFrequency::class);
    }

    /** @return UserFrequency[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :u')
            ->setParameter('u', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}