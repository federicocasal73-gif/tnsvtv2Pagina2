<?php

namespace App\Repository;

use App\Entity\DailyChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DailyChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyChallenge::class);
    }

    public function getTodayChallenge(): ?DailyChallenge
    {
        $today = (new \DateTime())->format('Y-m-d');
        return $this->findOneBy(['date' => $today]);
    }

    public function getChallengeByDate(string $date): ?DailyChallenge
    {
        return $this->findOneBy(['date' => $date]);
    }

    public function getRecentChallenges(int $limit = 7): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}