<?php

namespace App\Repository;

use App\Entity\CampusSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CampusSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampusSubmission::class);
    }

    public function findByUser(string $userCode): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->orderBy('s.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByAssignment(int $assignmentId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.assignment = :assignmentId')
            ->setParameter('assignmentId', $assignmentId)
            ->orderBy('s.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPendingByUser(string $userCode): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userCode = :userCode')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('userCode', $userCode)
            ->setParameter('statuses', [CampusSubmission::STATUS_PENDING, CampusSubmission::STATUS_SUBMITTED])
            ->orderBy('s.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByUser(string $userCode): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.userCode = :userCode')
            ->setParameter('userCode', $userCode)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function avgGradeByUser(string $userCode): ?float
    {
        $result = $this->createQueryBuilder('s')
            ->select('AVG(f.grade)')
            ->join('s.feedback', 'f')
            ->andWhere('s.userCode = :userCode')
            ->andWhere('s.status = :status')
            ->setParameter('userCode', $userCode)
            ->setParameter('status', CampusSubmission::STATUS_CORRECTED)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (float) $result : null;
    }

    public function findAllFiltered(?int $assignmentId, ?string $userCode, ?string $status, int $offset = 0, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.submittedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($assignmentId !== null) {
            $qb->andWhere('s.assignment = :assignmentId')
               ->setParameter('assignmentId', $assignmentId);
        }
        if ($userCode !== null && $userCode !== '') {
            $qb->andWhere('s.userCode = :userCode')
               ->setParameter('userCode', $userCode);
        }
        if ($status !== null && $status !== '') {
            // UI alias: the "Aprobadas" tab sends status=approved, which maps
            // to the canonical corrected+completed statuses.
            if ($status === 'approved') {
                $qb->andWhere('s.status IN (:statuses)')
                   ->setParameter('statuses', ['corrected', 'completed']);
            } else {
                $qb->andWhere('s.status = :status')
                   ->setParameter('status', $status);
            }
        }

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(?int $assignmentId, ?string $userCode, ?string $status): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)');

        if ($assignmentId !== null) {
            $qb->andWhere('s.assignment = :assignmentId')
               ->setParameter('assignmentId', $assignmentId);
        }
        if ($userCode !== null && $userCode !== '') {
            $qb->andWhere('s.userCode = :userCode')
               ->setParameter('userCode', $userCode);
        }
        if ($status !== null && $status !== '') {
            // Same UI alias as findAllFiltered: approved → corrected+completed.
            if ($status === 'approved') {
                $qb->andWhere('s.status IN (:statuses)')
                   ->setParameter('statuses', ['corrected', 'completed']);
            } else {
                $qb->andWhere('s.status = :status')
                   ->setParameter('status', $status);
            }
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findDistinctUserCodesWithActivity(): array
    {
        $result = $this->createQueryBuilder('s')
            ->select('DISTINCT s.userCode')
            ->getQuery()
            ->getScalarResult();

        return array_map('current', $result);
    }

    /**
     * Bulk submissions stats per user (1 query for N users).
     * @param string[] $userCodes
     * @return array<string,array{submissions:int,graded:int,avg:?float}>
     */
    public function statsGroupedByUser(array $userCodes): array
    {
        if ($userCodes === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('s')
            ->select('s.userCode as user_code, COUNT(s.id) as submissions')
            ->andWhere('s.userCode IN (:codes)')
            ->setParameter('codes', $userCodes)
            ->groupBy('s.userCode')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['user_code']] = [
                'submissions' => (int) $r['submissions'],
                'graded' => 0,
                'avg' => null,
            ];
        }

        // Grades live on CampusFeedback (OneToOne mapped by submission);
        // aggregate separately to keep the main count query index-friendly.
        $gradeRows = $this->createQueryBuilder('s')
            ->select('s.userCode as user_code, COUNT(f.id) as graded, AVG(f.grade) as avg')
            ->leftJoin('s.feedback', 'f')
            ->andWhere('s.userCode IN (:codes)')
            ->setParameter('codes', $userCodes)
            ->groupBy('s.userCode')
            ->getQuery()
            ->getResult();
        foreach ($gradeRows as $r) {
            $code = (string) $r['user_code'];
            if (!isset($map[$code])) {
                $map[$code] = ['submissions' => 0, 'graded' => 0, 'avg' => null];
            }
            $map[$code]['graded'] = (int) ($r['graded'] ?? 0);
            $map[$code]['avg'] = $r['avg'] !== null ? round((float) $r['avg'], 1) : null;
        }

        return $map;
    }
}
