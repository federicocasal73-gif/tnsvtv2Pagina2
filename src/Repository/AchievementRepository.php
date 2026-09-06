<?php

namespace App\Repository;

use App\Entity\Achievement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Achievement>
 */
class AchievementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Achievement::class);
    }

    /** @return Achievement[] */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.active = 1')
            ->orderBy('a.orden', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?Achievement
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
