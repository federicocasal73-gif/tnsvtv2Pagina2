<?php

namespace App\Repository;

use App\Entity\CampusQuizSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampusQuizSubmission>
 */
class CampusQuizSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusQuizSubmission::class);
    }
}
