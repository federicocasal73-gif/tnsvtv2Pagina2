<?php

namespace App\Repository;

use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAuditLog>
 */
class AdminAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /**
     * Ultimas N acciones (para panel admin).
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cuenta fallos de login admin desde una IP en los ultimos N minutos.
     */
    public function countRecentFailsByIp(string $ip, int $sinceMinutes = 60): int
    {
        $since = (new \DateTimeImmutable())->modify("-{$sinceMinutes} minutes");
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.action = :action')
            ->andWhere('a.result = :fail')
            ->andWhere('a.ip = :ip')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('action', AdminAuditLog::ACTION_LOGIN_FAIL)
            ->setParameter('fail', AdminAuditLog::RESULT_FAIL)
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}