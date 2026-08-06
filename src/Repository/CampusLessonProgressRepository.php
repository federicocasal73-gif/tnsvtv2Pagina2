<?php

namespace App\Repository;

use App\Entity\CampusLessonProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusLessonProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusLessonProgress::class);
    }

    public function findByUser(string $userCode): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->getQuery()
            ->getResult();
    }

    public function findByLesson(int $lessonId, string $userCode): ?CampusLessonProgress
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.lesson = :lessonId')
            ->andWhere('p.userCode = :userCode')
            ->setParameter('lessonId', $lessonId)
            ->setParameter('userCode', $userCode)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countCompletedByUser(string $userCode): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.userCode = :userCode')
            ->andWhere('p.completed = :completed')
            ->setParameter('userCode', $userCode)
            ->setParameter('completed', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotalByUser(string $userCode): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCompletedByCourse(string $userCode): array
    {
        return $this->createQueryBuilder('p')
            ->select('IDENTITY(m.course) as course_id, COUNT(p.id) as total, SUM(CASE WHEN p.completed = 1 THEN 1 ELSE 0 END) as completed')
            ->join('p.lesson', 'l')
            ->join('l.module', 'm')
            ->andWhere('p.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->groupBy('m.course')
            ->getQuery()
            ->getResult();
    }

    public function findDistinctUserCodes(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.userCode')
            ->getQuery()
            ->getScalarResult();

        return array_map('current', $result);
    }
}
