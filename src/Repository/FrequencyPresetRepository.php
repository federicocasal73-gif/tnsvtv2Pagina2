<?php

namespace App\Repository;

use App\Entity\FrequencyPreset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FrequencyPreset>
 */
class FrequencyPresetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FrequencyPreset::class);
    }

    /** @return FrequencyPreset[] */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.active = :a')
            ->setParameter('a', true)
            ->orderBy('f.frequency', 'ASC')
            ->getQuery()
            ->getResult();
    }
}