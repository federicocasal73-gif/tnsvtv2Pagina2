<?php

namespace App\Repository;

use App\Entity\TaskFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskFeedback>
 */
class TaskFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskFeedback::class);
    }

    public function findByTask(int $taskId): ?TaskFeedback
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.grader', 'g')->addSelect('g')
            ->where('f.task = :taskId')
            ->setParameter('taskId', $taskId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
