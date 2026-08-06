<?php

namespace App\Repository;

use App\Entity\ModuleProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ModuleProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModuleProgress::class);
    }

    public function findCompletedByUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'completed' => true]);
    }

    public function isModuleCompleted(User $user, string $moduleId): bool
    {
        return (bool) $this->count(['user' => $user, 'moduleId' => $moduleId, 'completed' => true]);
    }
}
