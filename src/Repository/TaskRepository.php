<?php

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
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
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.active = 1')
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

    public function getMaxOrden(): int
    {
        $max = (int)$this->createQueryBuilder('t')
            ->select('MAX(t.orden) AS max_orden')
            ->getQuery()
            ->getSingleScalarResult();
        return $max ?? -1;
    }

    /**
     * Find tasks with full filtering + sorting for the task list view.
     *
     * @param array{
     *     status?: string|null,
     *     priority?: string|null,
     *     type?: string|null,
     *     assigned_to?: string|null,
     *     assigned_by?: string|null,
     *     search?: string|null,
     *     due_before?: string|null,
     *     due_after?: string|null,
     *     overdue_only?: bool,
     *     sort?: string,
     *     order?: string,
     * } $filters
     * @return Task[]
     */
    public function findFiltered(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'a')->addSelect('a')
            ->leftJoin('t.assignedBy', 'b')->addSelect('b');

        if (!empty($filters['status'])) {
            $qb->andWhere('t.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['priority'])) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $filters['priority']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('t.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['assigned_to'])) {
            $qb->andWhere('a.code = :assigned_to')->setParameter('assigned_to', $filters['assigned_to']);
        }
        if (!empty($filters['assigned_by'])) {
            $qb->andWhere('b.code = :assigned_by')->setParameter('assigned_by', $filters['assigned_by']);
        }
        if (!empty($filters['search'])) {
            $qb->andWhere('t.title LIKE :q OR t.description LIKE :q')
               ->setParameter('q', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['due_before'])) {
            $qb->andWhere('t.dueDate IS NOT NULL AND t.dueDate <= :due_before')
               ->setParameter('due_before', new \DateTimeImmutable($filters['due_before']));
        }
        if (!empty($filters['due_after'])) {
            $qb->andWhere('t.dueDate IS NOT NULL AND t.dueDate >= :due_after')
               ->setParameter('due_after', new \DateTimeImmutable($filters['due_after']));
        }
        if (!empty($filters['overdue_only'])) {
            $now = new \DateTimeImmutable();
            $qb->andWhere('t.dueDate IS NOT NULL AND t.dueDate < :now AND t.status != :approved')
               ->setParameter('now', $now)
               ->setParameter('approved', Task::STATUS_APPROVED);
        }
        if (!empty($filters['active'])) {
            $qb->andWhere('t.active = :active')->setParameter('active', true);
        }

        // Sorting
        $sort = $filters['sort'] ?? 'due_date';
        $order = strtolower($filters['order'] ?? 'asc');
        $order = $order === 'desc' ? 'DESC' : 'ASC';

        $sortField = match ($sort) {
            'priority'    => 't.priority',
            'created'     => 't.createdAt',
            'updated'     => 't.updatedAt',
            'status'      => 't.status',
            'title'       => 't.title',
            'orden'       => 't.orden',
            default       => 't.dueDate',
        };
        $qb->orderBy($sortField, $order);
        // Stable secondary sort
        if ($sortField !== 't.id') {
            $qb->addOrderBy('t.id', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Task[] */
    public function findAssignedTo(User $user, array $filters = []): array
    {
        $filters['assigned_to'] = $user->getCode();
        return $this->findFiltered($filters);
    }

    /** @return Task[] */
    public function findAssignedBy(User $user, array $filters = []): array
    {
        $filters['assigned_by'] = $user->getCode();
        return $this->findFiltered($filters);
    }

    /**
     * Returns aggregate counts grouped by status for a user.
     *
     * @return array<string,int>
     */
    public function countByStatusForUser(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS total')
            ->groupBy('t.status');
        if ($user) {
            $qb->leftJoin('t.assignedTo', 'a')
               ->andWhere('a = :user OR t.assignedBy = :user')
               ->setParameter('user', $user);
        }
        $rows = $qb->getQuery()->getResult();

        $out = array_fill_keys([
            Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS, Task::STATUS_SUBMITTED,
            Task::STATUS_IN_REVIEW, Task::STATUS_APPROVED, Task::STATUS_NEEDS_REVISION,
            Task::STATUS_OVERDUE,
        ], 0);
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * Bulk-update `overdue` status for tasks whose due_date has passed and
     * are not yet approved. Returns the count of updated tasks.
     */
    public function markOverdue(): int
    {
        $now = new \DateTimeImmutable();
        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.status', ':status')
            ->set('t.updatedAt', ':now')
            ->where('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate < :dueBefore')
            ->andWhere('t.status NOT IN (:excluded)')
            ->andWhere('t.active = :active')
            ->setParameter('status', Task::STATUS_OVERDUE)
            ->setParameter('now', $now)
            ->setParameter('dueBefore', $now)
            ->setParameter('excluded', [Task::STATUS_APPROVED, Task::STATUS_OVERDUE])
            ->setParameter('active', true)
            ->getQuery()
            ->execute();
    }

    /**
     * Find tasks due within the next $days days for the given user.
     *
     * @return Task[]
     */
    public function findUpcomingForUser(User $user, int $days = 7, int $limit = 10): array
    {
        $now = new \DateTimeImmutable();
        $until = $now->modify("+{$days} days");
        return $this->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'a')->addSelect('a')
            ->where('a = :user')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate >= :now')
            ->andWhere('t.dueDate <= :until')
            ->andWhere('t.status NOT IN (:done)')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->setParameter('until', $until)
            ->setParameter('done', [Task::STATUS_APPROVED])
            ->orderBy('t.dueDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Tasks due today for the given user.
     *
     * @return Task[]
     */
    public function findDueTodayForUser(User $user): array
    {
        $start = new \DateTimeImmutable('today 00:00:00');
        $end = new \DateTimeImmutable('today 23:59:59');
        return $this->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'a')->addSelect('a')
            ->where('a = :user')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate >= :start')
            ->andWhere('t.dueDate <= :end')
            ->andWhere('t.status NOT IN (:done)')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('done', [Task::STATUS_APPROVED])
            ->orderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
