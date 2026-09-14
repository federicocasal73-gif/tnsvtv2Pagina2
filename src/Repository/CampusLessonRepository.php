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

    public function countTotalCatalog(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.module', 'm')
            ->join('m.course', 'c')
            ->andWhere('c.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotalPerCourse(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(m.course) as course_id, COUNT(l.id) as total')
            ->join('l.module', 'm')
            ->join('m.course', 'c')
            ->andWhere('c.isActive = :active')
            ->setParameter('active', true)
            ->groupBy('m.course')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['course_id']] = (int) $r['total'];
        }

        return $map;
    }
}
