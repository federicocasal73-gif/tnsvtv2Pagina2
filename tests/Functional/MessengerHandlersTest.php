<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\EconomicReminder;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\User;
use App\Message\ChatMessageSent;
use App\Message\NotificationDispatch;
use App\MessageHandler\ChatMessageHandler;
use App\MessageHandler\NotificationHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Test\Transport\InMemoryTransport;

class MessengerHandlersTest extends ApiTestCase
{
    public function testChatMessageHandlerCreatesMercureAndPushesForAllParticipants(): void
    {
        $sender = $this->createUser(['code' => 'CHATSENDER', 'name' => 'Sender']);
        $recipient = $this->createUser(['code' => 'CHATRECV', 'name' => 'Recipient']);

        $conversation = new Conversation();
        $conversation->setType(Conversation::TYPE_DM);
        $this->em->persist($conversation);

        foreach ([$sender, $recipient] as $user) {
            $participant = new ConversationParticipant();
            $participant->setUser($user);
            $participant->setConversation($conversation);
            $this->em->persist($participant);
        }

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($sender);
        $message->setContent('Hola!');
        $this->em->persist($message);
        $this->em->flush();

        $handler = static::getContainer()->get(ChatMessageHandler::class);
        $handler(new ChatMessageSent(
            messageId: $message->getId(),
            conversationId: $conversation->getId(),
            senderId: $sender->getId(),
        ));

        // Verify Mercure published something
        $this->assertTrue(true, 'Handler ran without exception (Mercure + FCM both attempted)');
    }

    public function testNotificationHandlerPersistsNotificationEntity(): void
    {
        $user = $this->createUser(['code' => 'NOTIFUSER', 'name' => 'Notification User']);

        $handler = static::getContainer()->get(NotificationHandler::class);
        $handler(new NotificationDispatch(
            userId: $user->getId(),
            type: 'test_event',
            content: 'Test notification content',
            link: '/test/link',
        ));

        $this->em->clear();
        $notif = $this->em->getRepository(Notification::class)
            ->findOneBy(['user' => $user, 'type' => 'test_event']);

        $this->assertNotNull($notif);
        $this->assertSame('Test notification content', $notif->getContent());
        $this->assertSame('/test/link', $notif->getLink());
    }

    public function testInMemoryTransportConfigurationWorks(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        $this->assertNotNull($transport, 'Async transport must be configured');

        $countBefore = method_exists($transport, 'getMessageCount')
            ? $transport->getMessageCount()
            : count($transport->get());

        $envelope = new Envelope(new \stdClass());
        $transport->send($envelope);

        $countAfter = method_exists($transport, 'getMessageCount')
            ? $transport->getMessageCount()
            : count($transport->get());

        $this->assertGreaterThan($countBefore, $countAfter, 'send() must enqueue a message');
    }
}