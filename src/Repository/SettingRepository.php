<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /** @return Setting[] */
    public function findAll(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.category', 'ASC')
            ->addOrderBy('s.key', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Setting[] */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.category = :c')
            ->setParameter('c', $category)
            ->orderBy('s.key', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getValue(string $key, ?string $default = null): ?string
    {
        $setting = $this->find($key);
        return $setting ? $setting->getValue() : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $setting = $this->find($key);
        return $setting ? $setting->getBoolValue() : $default;
    }
}