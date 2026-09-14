<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Singleton: the group conversation (type='group').
     */
    public function findGroupConversation(): ?Conversation
    {
        return $this->findOneBy(['type' => Conversation::TYPE_GROUP]);
    }

    /**
     * Conversations where the user is a participant, ordered by most recent message.
     * Returns array of [Conversation, lastMessage, unreadCount].
     *
     * @return array<int, array{conv: Conversation, lastMessage: ?\App\Entity\Message, unreadCount: int}>
     */
    public function findByParticipant(User $user): array
    {
        $em = $this->getEntityManager();

        // 1) Get conversation IDs where user participates (1 query)
        $participantConvIds = $em->createQueryBuilder()
            ->select('IDENTITY(p.conversation)')
            ->from(ConversationParticipant::class, 'p')
            ->andWhere('p.user = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleColumnResult();

        if (empty($participantConvIds)) return [];

        // 2) Load all conversations WITH participants joined (1 query, eager)
        $convs = $this->createQueryBuilder('c')
            ->leftJoin('c.participants', 'cp')
            ->addSelect('cp')
            ->leftJoin('cp.user', 'cu')
            ->addSelect('cu')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $participantConvIds)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // 3) Batch load last messages for all conversations (1 query)
        $lastMessageData = $em->createQueryBuilder()
            ->select('m, IDENTITY(m.conversation) AS conv_id')
            ->from(Message::class, 'm')
            ->andWhere('m.conversation IN (:ids)')
            ->setParameter('ids', $participantConvIds)
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();

        $lastMsgByConv = [];
        foreach ($lastMessageData as $row) {
            $cid = is_array($row) ? ($row['conv_id'] ?? null) : (method_exists($row, 'getConversation') ? $row->getConversation()?->getId() : null);
            $msg = is_array($row) ? ($row[0] ?? null) : $row;
            if ($cid && !isset($lastMsgByConv[$cid])) {
                $lastMsgByConv[$cid] = $msg;
            }
        }

        // 5+6) Batch count unread for all conversations (2 queries: one for
        // total unread, one for the read-before-lastReadAt correction).
        // Replaces the old N+1 loop that ran one COUNT per conversation with
        // a lastReadAt set — bounded and uses index idx_msg_conv_created.
        $participantRecords = $em->createQueryBuilder()
            ->select('p')
            ->from(ConversationParticipant::class, 'p')
            ->andWhere('p.conversation IN (:ids)')
            ->andWhere('p.user = :u')
            ->setParameter('ids', $participantConvIds)
            ->setParameter('u', $user)
            ->getQuery()
            ->getResult();

        $partByConv = [];
        $lastReadByConv = [];
        foreach ($participantRecords as $p) {
            $cid = $p->getConversation()?->getId();
            if ($cid === null) continue;
            $partByConv[$cid] = $p;
            $lastReadByConv[$cid] = $p->getLastReadAt();
        }

        $unreadRows = $em->createQueryBuilder()
            ->select('IDENTITY(m.conversation) AS cid, COUNT(m.id) AS cnt')
            ->from(Message::class, 'm')
            ->andWhere('m.conversation IN (:ids)')
            ->andWhere('(m.sender != :u OR m.sender IS NULL)')
            ->setParameter('ids', $participantConvIds)
            ->setParameter('u', $user)
            ->groupBy('m.conversation')
            ->getQuery()
            ->getResult();

        $unreadByConv = [];
        foreach ($unreadRows as $row) {
            $unreadByConv[(int) $row['cid']] = (int) $row['cnt'];
        }

        // Subtract read-before-lastReadAt only for conversations that have a
        // marker. One batch query grouped by conversation with CASE WHEN.
        $withMarker = array_filter($lastReadByConv, fn($lr) => $lr !== null);
        if ($withMarker !== []) {
            $markerIds = array_keys($withMarker);
            /** @var array<int<0, max>|string, \Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $types */
            $types = ['user_id' => \PDO::PARAM_INT, 'ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER];
            $rows = $em->getConnection()->executeQuery(
                'SELECT m.conversation_id AS cid, COUNT(*) AS read_before
                   FROM messages m
                   JOIN conversation_participants cp
                     ON cp.conversation_id = m.conversation_id
                    AND cp.user_id = :user_id
                  WHERE m.conversation_id IN (:ids)
                    AND (m.sender_id <> :user_id OR m.sender_id IS NULL)
                    AND m.created_at <= cp.last_read_at
                  GROUP BY m.conversation_id',
                ['user_id' => $user->getId(), 'ids' => $markerIds],
                $types
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                $cid = (int) $row['cid'];
                $unreadByConv[$cid] = max(0, ($unreadByConv[$cid] ?? 0) - (int) $row['read_before']);
            }
        }

        // Assemble result
        $result = [];
        foreach ($convs as $conv) {
            $cid = $conv->getId();
            $lastMessage = $lastMsgByConv[$cid] ?? null;
            $participant = $partByConv[$cid] ?? null;
            $unread = $unreadByConv[$cid] ?? 0;

            $result[] = [
                'conv' => $conv,
                'lastMessage' => $lastMessage,
                'unreadCount' => $unread,
            ];
        }

        return $result;
    }

    /**
     * Find or create a DM between two users. For Fase B — unused in Fase A.
     */
    public function findOrCreateDm(User $a, User $b): Conversation
    {
        if ($a === $b) {
            throw new \InvalidArgumentException('No se puede crear un DM con uno mismo');
        }

        // Find a DM where both are participants
        $existing = $this->createQueryBuilder('c')
            ->andWhere('c.type = :type')
            ->setParameter('type', Conversation::TYPE_DM)
            ->join('c.participants', 'p1', 'WITH', 'p1.user = :a')
            ->join('c.participants', 'p2', 'WITH', 'p2.user = :b')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing) return $existing;

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $em = $this->getEntityManager();
        $em->persist($conv);

        foreach ([$a, $b] as $user) {
            $p = new ConversationParticipant();
            $p->setConversation($conv);
            $p->setUser($user);
            $em->persist($p);
        }
        $em->flush();

        return $conv;
    }
}
