<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByCode(string $code): ?User
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findByCodeLike(string $q): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.code LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            ->orderBy('u.code', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated active users for the campus admin overview.
     * Includes users with zero progress (LEFT-join friendly: caller merges aggregates).
     * @return array{users: User[], total: int}
     */
    public function findActivePaginated(?string $search, int $offset, int $limit): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.active = :active')
            ->setParameter('active', true);
        $countQb = $this->createQueryBuilder('u2')
            ->select('COUNT(u2.id)')
            ->andWhere('u2.active = :active')
            ->setParameter('active', true);

        if ($search !== null && $search !== '') {
            $qb->andWhere('u.code LIKE :q OR u.name LIKE :q OR u.email LIKE :q')
                ->setParameter('q', '%' . $search . '%');
            $countQb->andWhere('u2.code LIKE :q OR u2.name LIKE :q OR u2.email LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $users = $qb->orderBy('u.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['users' => $users, 'total' => $total];
    }
}
