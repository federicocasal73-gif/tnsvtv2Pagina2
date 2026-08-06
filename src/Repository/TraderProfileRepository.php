<?php

namespace App\Repository;

use App\Entity\TraderProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TraderProfile>
 */
class TraderProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TraderProfile::class);
    }

    public function findByUser(User $user): ?TraderProfile
    {
        return $this->findOneBy(['user' => $user]);
    }
}
