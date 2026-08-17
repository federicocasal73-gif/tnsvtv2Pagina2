<?php

namespace App\Repository;

use App\Entity\MentorAvailability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MentorAvailability>
 */
class MentorAvailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MentorAvailability::class);
    }

    public function findByMentor(string $mentorCode, ?int $dayOfWeek = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.mentor) IN (SELECT u.id FROM App\\Entity\\User u WHERE u.code = :c)')
            ->setParameter('c', $mentorCode);
        if ($dayOfWeek !== null) {
            $qb->andWhere('a.dayOfWeek = :d')->setParameter('d', $dayOfWeek);
        }
        $qb->orderBy('a.dayOfWeek', 'ASC')
            ->addOrderBy('a.startTime', 'ASC');
        return $qb->getQuery()->getResult();
    }

    public function findByMentorAndDay(string $mentorCode, int $dayOfWeek): array
    {
        return $this->findByMentor($mentorCode, $dayOfWeek);
    }
}
