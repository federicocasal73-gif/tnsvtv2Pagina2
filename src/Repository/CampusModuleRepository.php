<?php

namespace App\Repository;

use App\Entity\CampusModule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusModuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusModule::class);
    }

    public function findByCourse(int $courseId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.course = :courseId')
            ->setParameter('courseId', $courseId)
            ->orderBy('m.orden', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
