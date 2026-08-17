<?php

namespace App\Repository;

use App\Entity\ConversationParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversationParticipant>
 */
class ConversationParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationParticipant::class);
    }

    /**
     * @return ConversationParticipant[]
     */
    public function findByConversation(int $conversationId): array
    {
        return $this->createQueryBuilder('cp')
            ->where('cp.conversation = :conv')
            ->setParameter('conv', $conversationId)
            ->getQuery()
            ->getResult();
    }
}