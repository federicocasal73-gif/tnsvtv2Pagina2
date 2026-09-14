<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;

class ChatMessagesAndAttachmentsTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'messages',
            'conversation_participants',
            'conversations',
        ]);
    }

    private function makeDm(string $aCode, string $bCode): array
    {
        $a = $this->createUser(['code' => $aCode, 'name' => 'User ' . $aCode]);
        $b = $this->createUser(['code' => $bCode, 'name' => 'User ' . $bCode]);
        $conv = (new Conversation())->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);
        foreach ([$a, $b] as $u) {
            $p = (new ConversationParticipant())->setConversation($conv)->setUser($u);
            $this->em->persist($p);
        }
        $this->em->flush();
        // Detach so the controller reloads participants from DB instead of
        // relying on the in-memory inverse-side collection.
        $this->em->clear();
        return [$a, $b, $conv];
    }

    public function testAfterIdReturnsOnlyNewerMessages(): void
    {
        [$a, $b, $conv] = $this->makeDm('AFTA', 'AFTB');
        $convId = $conv->getId();
        // After em->clear() in makeDm, create fresh references for messages.
        $convRef = $this->em->getReference(\App\Entity\Conversation::class, $convId);
        $aRef = $this->em->getReference(\App\Entity\User::class, $a->getId());
        $msg1 = (new Message())->setConversation($convRef)->setSender($aRef)->setContent('hello');
        $msg2 = (new Message())->setConversation($convRef)->setSender($aRef)->setContent('world');
        $this->em->persist($msg1);
        $this->em->persist($msg2);
        $this->em->flush();
        $this->em->clear();
        // msg1 has the smaller id.
        $r = $this->jsonRequest('GET', '/api/chat/conversations/'.$convId.'/messages?user_code=AFTA&after_id='.$msg1->getId());
        $this->assertSame(200, $r['status']);
        $this->assertCount(1, $r['data']);
        $this->assertSame($msg2->getId(), $r['data'][0]['id']);
    }

    public function testAttachmentSignerRejectsBadToken(): void
    {
        [$a] = $this->makeDm('ATT1', 'ATT2');
        $r = $this->jsonRequest('GET', '/api/chat/attachment?token=garbage.payload');
        $this->assertSame(403, $r['status']);
    }

    public function testAttachmentSignRequiresParticipant(): void
    {
        [$a, , $conv] = $this->makeDm('SIGN1', 'SIGN2');
        $outsider = $this->createUser(['code' => 'SIGNX', 'name' => 'Outsider']);
        $r = $this->jsonRequest('POST', '/api/chat/attachment/sign', [
            'user_code' => 'SIGNX',
            'conversation_id' => $conv->getId(),
            'url' => '/uploads/chat/some.png',
        ]);
        $this->assertSame(403, $r['status']);
    }

    public function testAttachmentSignAcceptsChatPath(): void
    {
        [$a, , $conv] = $this->makeDm('SIGA', 'SIGB');
        $r = $this->jsonRequest('POST', '/api/chat/attachment/sign', [
            'user_code' => 'SIGA',
            'conversation_id' => $conv->getId(),
            'url' => '/uploads/chat/' . str_repeat('a', 30) . '.png',
        ]);
        $this->assertSame(200, $r['status']);
        $this->assertStringStartsWith('/api/chat/attachment?token=', $r['data']['signed_url']);
    }

    public function testAttachmentSignRejectsPathTraversal(): void
    {
        [$a, , $conv] = $this->makeDm('TST1', 'TST2');
        $r = $this->jsonRequest('POST', '/api/chat/attachment/sign', [
            'user_code' => 'TST1',
            'conversation_id' => $conv->getId(),
            'url' => '/uploads/../etc/passwd',
        ]);
        $this->assertSame(400, $r['status']);
    }

    public function testTypingDoesNotPersistNotification(): void
    {
        [$a, , $conv] = $this->makeDm('TYP1', 'TYP2');
        // Typing should be ephemeral — the recipient must not see a stored
        // notification row, only the realtime Mercure event.
        $r = $this->jsonRequest('POST', '/api/chat/typing', [
            'user_code' => 'TYP1',
            'conversation_id' => $conv->getId(),
        ]);
        $this->assertSame(200, $r['status']);
        $count = (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM notifications WHERE type = :t',
            ['t' => 'typing']
        )->fetchOne();
        $this->assertSame(0, $count, 'Typing must not persist notifications');
    }

    public function testDeleteConversationGroupRequiresAdmin(): void
    {
        $owner = $this->createUser(['code' => 'OWNG', 'name' => 'Owner']);
        $other = $this->createUser(['code' => 'GUSR', 'name' => 'Other']);
        $conv = (new Conversation())->setType(Conversation::TYPE_GROUP)->setTitle('Test group');
        $this->em->persist($conv);
        foreach ([$owner, $other] as $u) {
            $this->em->persist((new ConversationParticipant())->setConversation($conv)->setUser($u));
        }
        $this->em->flush();

        $r = $this->jsonRequest('DELETE', '/api/chat/conversations/'.$conv->getId().'?user_code=OWNG', []);
        $this->assertSame(403, $r['status'], 'Non-admin cannot delete a group');
    }
}
