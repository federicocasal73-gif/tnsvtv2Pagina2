<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    public function findByToken(string $fcmToken): ?Device
    {
        return $this->findOneBy(['fcmToken' => $fcmToken]);
    }

    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }
}
