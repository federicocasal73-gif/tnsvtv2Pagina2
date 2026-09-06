<?php

namespace App\Repository;

use App\Entity\UserAchievement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAchievement>
 */
class UserAchievementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAchievement::class);
    }

    /** @return UserAchievement[] */
    public function findByUser(string $userCode): array
    {
        return $this->createQueryBuilder('ua')
            ->join('ua.achievement', 'a')->addSelect('a')
            ->where('ua.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->orderBy('ua.unlockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasAchievement(string $userCode, int $achievementId): bool
    {
        return (bool) $this->createQueryBuilder('ua')
            ->select('1')
            ->where('ua.userCode = :userCode')
            ->andWhere('ua.achievement = :aid')
            ->setParameter('userCode', $userCode)
            ->setParameter('aid', $achievementId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
