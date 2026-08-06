<?php

namespace App\Repository;

use App\Entity\CampusMaterial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusMaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusMaterial::class);
    }

    public function findByLesson(int $lessonId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.lesson = :lessonId')
            ->setParameter('lessonId', $lessonId)
            ->orderBy('m.orden', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
