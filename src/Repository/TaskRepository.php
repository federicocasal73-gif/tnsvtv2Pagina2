<?php

namespace App\Repository;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /** @return Task[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.orden', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Task[] */
    public function findAllActiveOrdered(): array
    {
        return $this->findActiveOrdered();
    }

    /** @return Task[] */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.active = 1')
            ->orderBy('t.orden', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Get the current max orden value (for appending). Returns -1 if no tasks. */
    public function getMaxOrden(): int
    {
        $max = (int)$this->createQueryBuilder('t')
            ->select('MAX(t.orden) AS max_orden')
            ->getQuery()
            ->getSingleScalarResult();
        return $max ?? -1;
    }
}