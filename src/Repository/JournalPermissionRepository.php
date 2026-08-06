<?php

namespace App\Repository;

use App\Entity\JournalPermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class JournalPermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalPermission::class);
    }

    public function findByGrantorAndGrantee($grantor, $grantee): ?JournalPermission
    {
        return $this->findOneBy(['grantor' => $grantor, 'grantee' => $grantee]);
    }
}
