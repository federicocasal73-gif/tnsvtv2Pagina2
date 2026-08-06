<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Latest messages of a conversation, paginated descending by id.
     * Pass $beforeId to fetch messages strictly older than that id.
     *
     * @return Message[]
     */
    public function findByConversation(Conversation $conv, int $limit = 50, ?int $beforeId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conv')
            ->setParameter('conv', $conv)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit);

        if ($beforeId !== null) {
            $qb->andWhere('m.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return array_reverse($qb->getQuery()->getResult());
    }

    /**
     * Messages of a conversation with id strictly greater than $afterId.
     * Used for polling.
     *
     * @return Message[]
     */
    public function findNewerThan(Conversation $conv, int $afterId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conv')
            ->andWhere('m.id > :afterId')
            ->setParameter('conv', $conv)
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
