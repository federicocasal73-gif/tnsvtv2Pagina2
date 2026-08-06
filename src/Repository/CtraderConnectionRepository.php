<?php

namespace App\Repository;

use App\Entity\CtraderConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CtraderConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CtraderConnection::class);
    }

    public function findActiveByUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'isActive' => true],
            ['createdAt' => 'DESC']
        );
    }

    public function findActiveByUserAndAccountId(User $user, string $ctraderAccountId): ?CtraderConnection
    {
        return $this->findOneBy([
            'user' => $user,
            'ctraderAccountId' => $ctraderAccountId,
            'isActive' => true,
        ]);
    }

    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
