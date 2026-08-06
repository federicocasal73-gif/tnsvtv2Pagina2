<?php

namespace App\Repository;

use App\Entity\CampusEnrollment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusEnrollment::class);
    }

    public function findByUser(string $userCode): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->getQuery()
            ->getResult();
    }

    public function findByCourse(int $courseId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.course = :courseId')
            ->setParameter('courseId', $courseId)
            ->getQuery()
            ->getResult();
    }

    public function isEnrolled(int $courseId, string $userCode): bool
    {
        $result = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.course = :courseId')
            ->andWhere('e.userCode = :userCode')
            ->setParameter('courseId', $courseId)
            ->setParameter('userCode', $userCode)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    public function countStudentsByCourse(int $courseId): int
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.course = :courseId')
            ->setParameter('courseId', $courseId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
