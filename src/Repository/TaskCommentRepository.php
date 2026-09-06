<?php

namespace App\Repository;

use App\Entity\TaskComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskComment>
 */
class TaskCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskComment::class);
    }

    /** @return TaskComment[] */
    public function findByTask(int $taskId): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->where('c.task = :taskId')
            ->setParameter('taskId', $taskId)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
