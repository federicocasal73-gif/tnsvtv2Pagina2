<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkPreview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LinkPreview>
 */
class LinkPreviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkPreview::class);
    }

    public function findByHash(string $hash): ?LinkPreview
    {
        return $this->findOneBy(['urlHash' => $hash]);
    }

    public function findFreshByHash(string $hash): ?LinkPreview
    {
        $preview = $this->findByHash($hash);
        if ($preview === null) {
            return null;
        }
        return $preview->isExpired() ? null : $preview;
    }

    /**
     * @return LinkPreview[]
     */
    public function findStale(\DateTimeImmutable $cutoff, int $limit = 200): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.expiresAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('p.expiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function deleteStale(\DateTimeImmutable $cutoff, int $batchSize = 500): int
    {
        $deleted = 0;
        $qb = $this->getEntityManager()->createQueryBuilder();
        $loop = true;
        while ($loop) {
            $ids = $qb
                ->select('p.id')
                ->from(LinkPreview::class, 'p')
                ->where('p.expiresAt < :cutoff')
                ->setParameter('cutoff', $cutoff)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getArrayResult();
            if ($ids === []) {
                $loop = false;
                break;
            }
            $idList = array_column($ids, 'id');
            $del = $this->getEntityManager()->createQueryBuilder()
                ->delete(LinkPreview::class, 'p')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', $idList)
                ->getQuery()
                ->execute();
            $deleted += $del;
            if (count($idList) < $batchSize) {
                $loop = false;
            }
        }
        return $deleted;
    }
}