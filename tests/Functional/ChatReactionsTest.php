<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;

class ChatReactionsTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'messages',
            'conversation_participants',
            'conversation',
            'conversations',
        ]);
    }

    private function makeDm(string $aCode, string $bCode): array
    {
        $a = $this->createUser(['code' => $aCode, 'name' => 'User ' . $aCode]);
        $b = $this->createUser(['code' => $bCode, 'name' => 'User ' . $bCode]);

        $conv = new Conversation();
        $conv->setType(Conversation::TYPE_DM);
        $this->em->persist($conv);
        foreach ([$a, $b] as $u) {
            $p = new ConversationParticipant();
            $p->setUser($u);
            $p->setConversation($conv);
            $this->em->persist($p);
        }
        $msg = new Message();
        $msg->setConversation($conv);
        $msg->setSender($a);
        $msg->setContent('Hola');
        $this->em->persist($msg);
        $this->em->flush();
        // Detach so the controller reloads participants/messages from the
        // DB (the in-memory inverse-side collection would otherwise be empty).
        $this->em->clear();

        return [$a, $b, $conv, $msg];
    }

    private function react(string $userCode, int $convId, int $msgId, string $emoji): array
    {
        return $this->jsonRequest(
            'POST',
            "/api/chat/conversations/{$convId}/messages/{$msgId}/react",
            ['user_code' => $userCode, 'emoji' => $emoji]
        );
    }

    public function testReactAddsAndTogglesOff(): void
    {
        [$a, $b, $conv, $msg] = $this->makeDm('REACTA1', 'REACTB1');

        $r1 = $this->react('REACTB1', $conv->getId(), $msg->getId(), '👍');
        $this->assertSame(200, $r1['status'], 'Body: ' . json_encode($r1['data']));
        $this->assertSame(['REACTB1'], $r1['data']['reactions']['👍'] ?? null);

        $r2 = $this->react('REACTB1', $conv->getId(), $msg->getId(), '👍');
        $this->assertSame(200, $r2['status']);
        $this->assertArrayNotHasKey('👍', $r2['data']['reactions'] ?? []);
    }

    public function testReactRejectsInvalidEmoji(): void
    {
        [$a, $b, $conv, $msg] = $this->makeDm('REACTA2', 'REACTB2');

        $r = $this->react('REACTB2', $conv->getId(), $msg->getId(), 'not-an-emoji');
        $this->assertSame(400, $r['status']);
    }

    public function testReactRequiresParticipant(): void
    {
        [$a, $b, $conv, $msg] = $this->makeDm('REACTA3', 'REACTB3');
        $this->createUser(['code' => 'OUTSIDER3', 'name' => 'Outsider']);

        $r = $this->react('OUTSIDER3', $conv->getId(), $msg->getId(), '🔥');
        $this->assertSame(403, $r['status']);
    }

    public function testReadByAppearsAfterMarkRead(): void
    {
        [$a, $b, $conv, $msg] = $this->makeDm('READA4', 'READB4');

        // Before B reads: A's view shows empty read_by.
        $list1 = $this->jsonRequest('GET', "/api/chat/conversations/{$conv->getId()}/messages?user_code=READA4");
        $this->assertSame(200, $list1['status']);
        $this->assertSame([], $list1['data'][0]['read_by'] ?? null);

        // B marks the conversation as read.
        $mark = $this->jsonRequest('POST', "/api/chat/conversations/{$conv->getId()}/read", ['user_code' => 'READB4']);
        $this->assertSame(200, $mark['status']);

        // A's view now shows B in read_by.
        $this->em->clear();
        $list2 = $this->jsonRequest('GET', "/api/chat/conversations/{$conv->getId()}/messages?user_code=READA4");
        $this->assertSame(200, $list2['status']);
        $this->assertSame(['READB4'], $list2['data'][0]['read_by'] ?? null);
    }
}
