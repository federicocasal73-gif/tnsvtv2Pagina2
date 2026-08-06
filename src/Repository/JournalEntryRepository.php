<?php

namespace App\Repository;

use App\Entity\JournalEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JournalEntry>
 */
class JournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalEntry::class);
    }

    /**
     * @return JournalEntry[]
     */
    public function findSinceForUser(string $userCode, int $sinceTs): array
    {
        $sinceDt = (new \DateTimeImmutable())->setTimestamp($sinceTs);
        return $this->createQueryBuilder('j')
            ->where('j.userCode = :uc')
            ->andWhere('j.updatedAt > :since')
            ->setParameter('uc', $userCode)
            ->setParameter('since', $sinceDt)
            ->orderBy('j.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllForUser(string $userCode): array
    {
        return $this->findBy(['userCode' => $userCode], ['updatedAt' => 'DESC']);
    }

    public function findByUserCode(string $userCode): array
    {
        return $this->findBy(['userCode' => $userCode], ['date' => 'DESC', 'id' => 'DESC']);
    }

    public function findByUserCodeAndAccount(string $userCode, ?string $accountId): array
    {
        if ($accountId === null || $accountId === '') {
            return $this->findBy(['userCode' => $userCode, 'accountId' => null], ['date' => 'DESC', 'id' => 'DESC']);
        }
        return $this->findBy(['userCode' => $userCode, 'accountId' => $accountId], ['date' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return array<int, array{account_id: ?string, n: int}>
     */
    public function countTradesPerAccountByUser(string $userCode): array
    {
        $rows = $this->createQueryBuilder('j')
            ->select('j.accountId AS account_id, COUNT(j.id) AS n')
            ->where('j.userCode = :uc')
            ->setParameter('uc', $userCode)
            ->groupBy('j.accountId')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'account_id' => $r['account_id'] ?? null,
                'n' => (int) $r['n'],
            ];
        }
        return $out;
    }

    public function countByUserAndAccount(string $userCode, ?string $accountId): int
    {
        $qb = $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.userCode = :uc')
            ->setParameter('uc', $userCode);

        if ($accountId === null || $accountId === '') {
            $qb->andWhere('j.accountId IS NULL');
        } else {
            $qb->andWhere('j.accountId = :acc')
               ->setParameter('acc', $accountId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
