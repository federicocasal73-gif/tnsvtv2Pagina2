<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;

/**
 * Reproduces the production 500 on GET /api/chat/conversations for admin
 * users (prod body showed "InvalidOperation: Cannot index into a null array").
 *
 * The exact line that fails on prod isn't deterministic — depends on the
 * shape of the participant/message data — but this test exercises the same
 * code path an admin user takes on every page that loads the chat shell.
 *
 * If this test ever fails with a 500, the fix should be in
 * src/Repository/ConversationRepository::findByParticipant (or wherever
 * the null-array deref is), not in this test.
 */
class ChatAdminConversationsTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'messages',
            'conversation_participants',
            'conversations',
            'conversation',
        ]);
    }

    public function testAdminWithSingleDmGets200(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMIN01', 'name' => 'Test Admin']);
        $regular = $this->createUser(['code' => 'USER001', 'name' => 'Test User']);

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);

        $p1 = new ConversationParticipant(); $p1->setConversation($conv); $p1->setUser($admin);
        $p2 = new ConversationParticipant(); $p2->setConversation($conv); $p2->setUser($regular);
        $this->em->persist($p1);
        $this->em->persist($p2);

        $msg = new Message();
        $msg->setConversation($conv);
        $msg->setSender($regular);
        $msg->setContent('Hola admin');
        $this->em->persist($msg);

        $this->em->flush();
        $this->em->clear();

        $r = $this->jsonRequest('GET', '/api/chat/conversations?user_code=ADMIN01');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
    }

    public function testAdminWithManyConversationsGroupAndDm(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMIN01', 'name' => 'Test Admin']);
        $regular = $this->createUser(['code' => 'USER001', 'name' => 'Test User']);

        for ($i = 0; $i < 3; $i++) {
            $other = $this->createUser(['code' => 'BULK' . $i, 'name' => 'Bulk ' . $i]);
            $conv = new Conversation();
            $conv->setType(Conversation::TYPE_DM);
            $this->em->persist($conv);
            foreach ([$admin, $other] as $u) {
                $p = new ConversationParticipant();
                $p->setConversation($conv);
                $p->setUser($u);
                $this->em->persist($p);
            }
            $msg = new Message();
            $msg->setConversation($conv);
            $msg->setSender($other);
            $msg->setContent('Mensaje ' . $i);
            $this->em->persist($msg);
        }

        $group = new Conversation();
        $group->setType(Conversation::TYPE_GROUP);
        $group->setTitle('General');
        $this->em->persist($group);
        $pa = new ConversationParticipant();
        $pa->setConversation($group);
        $pa->setUser($admin);
        $this->em->persist($pa);
        $groupMsg = new Message();
        $groupMsg->setConversation($group);
        $groupMsg->setSender($admin);
        $groupMsg->setContent('Bienvenidos al grupo general');
        $this->em->persist($groupMsg);

        $this->em->flush();
        $this->em->clear();

        $r = $this->jsonRequest('GET', '/api/chat/conversations?user_code=ADMIN01');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
    }

    public function testRegularUserBaselineReturns200(): void
    {
        $regular = $this->createUser(['code' => 'USER001', 'name' => 'Test User']);
        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);
        $p = new ConversationParticipant();
        $p->setConversation($conv);
        $p->setUser($regular);
        $this->em->persist($p);
        $this->em->flush();
        $this->em->clear();

        $r = $this->jsonRequest('GET', '/api/chat/conversations?user_code=USER001');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
    }

    /**
     * Edge case: admin has conversations with last_read_at set AND
     * messages with sender_id NULL. Exercises the marker-count path in
     * ConversationRepository::findByParticipant which previously crashed
     * on prod for ADMIN01 with "Cannot index into a null array".
     */
    public function testAdminWithMarkersAndNullSenderMessagesReturns200(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMIN01', 'name' => 'Test Admin']);
        $other = $this->createUser(['code' => 'OTHER01', 'name' => 'Other']);

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);

        $pa = new ConversationParticipant();
        $pa->setConversation($conv);
        $pa->setUser($admin);
        $pa->setLastReadAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($pa);

        $po = new ConversationParticipant();
        $po->setConversation($conv);
        $po->setUser($other);
        $this->em->persist($po);

        // Message WITHOUT sender (e.g. system message).
        $sysMsg = new Message();
        $sysMsg->setConversation($conv);
        $sysMsg->setContent('Conversación iniciada');
        $this->em->persist($sysMsg);

        // Message with sender that admin already read.
        $old = new Message();
        $old->setConversation($conv);
        $old->setSender($other);
        $old->setContent('Hola');
        $old->setCreatedAt(new \DateTimeImmutable('-2 hours'));
        $this->em->persist($old);

        $this->em->flush();
        $this->em->clear();

        $r = $this->jsonRequest('GET', '/api/chat/conversations?user_code=ADMIN01');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertGreaterThanOrEqual(1, count($r['data']));
        $this->assertSame('OTHER01', $r['data'][0]['other_user_code'] ?? null);
    }

    /**
     * Edge case: empty messenger conversations (no messages at all).
     * Exercises the lastMessageData foreach with `$row['conv_id']` missing
     * or null.
     */
    public function testAdminInEmptyConversationReturns200(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMIN01', 'name' => 'Test Admin']);
        $other = $this->createUser(['code' => 'OTHER01', 'name' => 'Other']);

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);

        $pa = new ConversationParticipant();
        $pa->setConversation($conv);
        $pa->setUser($admin);
        $this->em->persist($pa);

        $po = new ConversationParticipant();
        $po->setConversation($conv);
        $po->setUser($other);
        $this->em->persist($po);

        // No messages. lastMessage loop is empty. Should still return 200.
        $this->em->flush();
        $this->em->clear();

        $r = $this->jsonRequest('GET', '/api/chat/conversations?user_code=ADMIN01');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertSame(1, count($r['data']));
        $this->assertArrayHasKey('last_message', $r['data'][0]);
        $this->assertNull($r['data'][0]['last_message']);
    }

    /**
     * The page calls /api/chat/users?q=… (the widget too). Asserts the `q`
     * param actually filters instead of returning everyone.
     */
    public function testUserSearchFiltersByQParam(): void
    {
        $this->createUser(['code' => 'SEARCHABLE1', 'name' => 'Buscar Este']);
        $this->createUser(['code' => 'ZZZOTHER', 'name' => 'Otro Usuario']);

        $r = $this->jsonRequest('GET', '/api/chat/users?user_code=SEARCHABLE1&q=buscar');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $codes = array_column($r['data'], 'code');
        $this->assertContains('SEARCHABLE1', $codes);
        $this->assertNotContains('ZZZOTHER', $codes);
    }

    /**
     * Sending a text message must return 201 and persist, even if the
     * post-save side effects (Mercure/bus/notifier) blow up — those are
     * best-effort and must never turn a saved message into a 500.
     */
    public function testSendMessageReturns201AndPersists(): void
    {
        $a = $this->createUser(['code' => 'SENDA', 'name' => 'Sender A']);
        $b = $this->createUser(['code' => 'SENDB', 'name' => 'Sender B']);

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);
        foreach ([$a, $b] as $u) {
            $p = new ConversationParticipant();
            $p->setConversation($conv);
            $p->setUser($u);
            $this->em->persist($p);
        }
        $this->em->flush();
        $this->em->clear();
        $convId = $conv->getId();

        $r = $this->jsonRequest('POST', "/api/chat/conversations/{$convId}/messages", [
            'user_code' => 'SENDA',
            'content' => 'Hola desde el test',
        ]);
        $this->assertSame(201, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertSame('Hola desde el test', $r['data']['content'] ?? null);

        $list = $this->jsonRequest('GET', "/api/chat/conversations/{$convId}/messages?user_code=SENDB");
        $this->assertSame(200, $list['status']);
        $this->assertCount(1, $list['data']);
    }
}
