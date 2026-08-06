<?php

namespace App\Repository;

use App\Entity\JournalSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class JournalSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalSetting::class);
    }

    public function findByUser($user): ?JournalSetting
    {
        return $this->findOneBy(['user' => $user]);
    }
}
