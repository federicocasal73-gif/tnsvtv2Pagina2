<?php

namespace App\Repository;

use App\Entity\AcademiaContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AcademiaContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademiaContent::class);
    }

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['orden' => 'ASC']);
    }
}
