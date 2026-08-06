<?php

namespace App\Repository;

use App\Entity\HonorBoard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HonorBoardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HonorBoard::class);
    }

    public function getBoard(string $category, string $period = 'all_time', string $season = '', int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('h')
            ->where('h.category = :category')
            ->andWhere('h.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->orderBy('h.value', 'DESC')
            ->setMaxResults($limit);

        if ($season) {
            $qb->andWhere('h.season = :season')
               ->setParameter('season', $season);
        }

        return $qb->getQuery()->getResult();
    }

    public function updateOrCreate(int $userId, string $category, string $period, string $season, int $value): HonorBoard
    {
        $entry = $this->findOneBy([
            'user' => $userId,
            'category' => $category,
            'period' => $period,
            'season' => $season,
        ]);

        if (!$entry) {
            $user = $this->getEntityManager()->getReference(\App\Entity\User::class, $userId);
            $entry = new HonorBoard();
            $entry->setUser($user);
            $entry->setCategory($category);
            $entry->setPeriod($period);
            $entry->setSeason($season);
        }

        if ($value > $entry->getValue()) {
            $entry->setValue($value);
            $entry->setUpdatedAt(new \DateTimeImmutable());
        }

        $em = $this->getEntityManager();
        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    public function getUserHonors(int $userId): array
    {
        return $this->findBy(['user' => $userId], ['value' => 'DESC']);
    }
}