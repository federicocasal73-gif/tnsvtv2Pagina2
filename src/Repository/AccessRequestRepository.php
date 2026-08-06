<?php

namespace App\Repository;

use App\Entity\AccessRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    public function findByTargetAndStatus($target, string $status): array
    {
        return $this->findBy(['target' => $target, 'status' => $status], ['createdAt' => 'DESC']);
    }

    public function findByRequesterAndStatus($requester, string $status): array
    {
        return $this->findBy(['requester' => $requester, 'status' => $status], ['createdAt' => 'DESC']);
    }

    public function findExisting($requester, $target): ?AccessRequest
    {
        return $this->findOneBy(['requester' => $requester, 'target' => $target]);
    }
}
