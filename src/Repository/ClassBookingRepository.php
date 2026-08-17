<?php

namespace App\Repository;

use App\Entity\ClassBooking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassBooking>
 */
class ClassBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassBooking::class);
    }

    /**
     * @param array{
     *   student_code?: string,
     *   mentor_code?: string,
     *   status?: string|null,
     *   upcoming_only?: bool
     * } $filters
     */
    public function findFiltered(array $filters): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.student', 's')->addSelect('s')
            ->leftJoin('b.mentor', 'm')->addSelect('m')
            ->orderBy('b.startAt', 'ASC');

        if (!empty($filters['student_code'])) {
            $qb->andWhere('s.code = :sc')->setParameter('sc', $filters['student_code']);
        }
        if (!empty($filters['mentor_code'])) {
            $qb->andWhere('m.code = :mc')->setParameter('mc', $filters['mentor_code']);
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('b.status = :st')->setParameter('st', $filters['status']);
        }
        if (!empty($filters['upcoming_only'])) {
            $qb->andWhere('b.startAt >= :now')->setParameter('now', new \DateTimeImmutable());
        }
        return $qb->getQuery()->getResult();
    }
}
