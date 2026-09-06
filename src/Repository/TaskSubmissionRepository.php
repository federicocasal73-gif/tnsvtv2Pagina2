<?php

namespace App\Repository;

use App\Entity\TaskSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskSubmission>
 */
class TaskSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskSubmission::class);
    }

    /** @return TaskSubmission[] */
    public function findByTask(int $taskId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.task = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('s.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForTask(int $taskId): ?TaskSubmission
    {
        return $this->createQueryBuilder('s')
            ->where('s.task = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('s.submittedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
