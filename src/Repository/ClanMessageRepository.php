<?php

namespace App\Repository;

use App\Entity\ClanMessage;
use App\Entity\Clan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClanMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClanMessage::class);
    }

    public function getRecentMessages(Clan $clan, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.clan = :clan')
            ->setParameter('clan', $clan)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getMessagesAfter(Clan $clan, \DateTimeImmutable $after): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.clan = :clan')
            ->andWhere('m.createdAt > :after')
            ->setParameter('clan', $clan)
            ->setParameter('after', $after)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function addSystemMessage(Clan $clan, string $content): ClanMessage
    {
        $msg = new ClanMessage();
        $msg->setClan($clan);
        $msg->setContent($content);
        $msg->setType('system');
        
        $em = $this->getEntityManager();
        $em->persist($msg);
        $em->flush();
        
        return $msg;
    }
}