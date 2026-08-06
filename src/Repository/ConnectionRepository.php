<?php

namespace App\Repository;

use App\Entity\Connection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Connection::class);
    }

    public function findByUser($user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function findExisting($user, $connectedUser): ?Connection
    {
        return $this->findOneBy(['user' => $user, 'connectedUser' => $connectedUser]);
    }

    public function areConnected($user, $other): bool
    {
        $direct = $this->findOneBy(['user' => $user, 'connectedUser' => $other]);
        if ($direct) return true;
        return (bool) $this->findOneBy(['user' => $other, 'connectedUser' => $user]);
    }
}
