<?php

namespace App\Repository;

use App\Entity\CalendarEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    /**
     * @param \DateTimeInterface|null $start Lower bound (inclusive) — null = -∞
     * @param \DateTimeInterface|null $end   Upper bound (exclusive) — null = +∞
     * @return CalendarEvent[]
     */
    public function findInRange(?\DateTimeInterface $start, ?\DateTimeInterface $end, ?string $type = null, ?string $mentorCode = null): array
    {
        $qb = $this->createQueryBuilder('e');
        if ($start) $qb->andWhere('e.startsAt >= :s')->setParameter('s', $start);
        if ($end)   $qb->andWhere('e.startsAt <  :e2')->setParameter('e2', $end);
        if ($type)  $qb->andWhere('e.type = :t')->setParameter('t', $type);
        if ($mentorCode) {
            $qb->andWhere('IDENTITY(e.mentor) IN (SELECT u2.id FROM App\\Entity\\User u2 WHERE u2.code = :mc)')
               ->setParameter('mc', $mentorCode);
        }
        $qb->orderBy('e.startsAt', 'ASC');
        return $qb->getQuery()->getResult();
    }
}
