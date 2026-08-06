<?php

namespace App\Repository;

use App\Entity\CampusAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusAssignment::class);
    }

    public function findByLesson(int $lessonId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.lesson = :lessonId')
            ->setParameter('lessonId', $lessonId)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
