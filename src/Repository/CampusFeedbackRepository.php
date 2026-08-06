<?php

namespace App\Repository;

use App\Entity\CampusFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusFeedback::class);
    }

    public function findBySubmission(int $submissionId): ?CampusFeedback
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.submission = :submissionId')
            ->setParameter('submissionId', $submissionId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
