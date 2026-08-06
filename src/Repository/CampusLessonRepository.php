<?php

namespace App\Repository;

use App\Entity\CampusLesson;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusLessonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusLesson::class);
    }

    public function findByModule(int $moduleId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.module = :moduleId')
            ->setParameter('moduleId', $moduleId)
            ->orderBy('l.orden', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
