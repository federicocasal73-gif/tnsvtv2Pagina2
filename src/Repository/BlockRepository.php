<?php

namespace App\Repository;

use App\Entity\Block;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Block::class);
    }

    public function isBlocked(User $blocker, User $blocked): bool
    {
        return (bool) $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    public function findByBlocker(User $blocker): array
    {
        return $this->findBy(['blocker' => $blocker], ['createdAt' => 'DESC']);
    }
}
