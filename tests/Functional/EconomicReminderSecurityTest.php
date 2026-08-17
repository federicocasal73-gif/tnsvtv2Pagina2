<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EconomicReminder;
use App\Service\Auth\JwtService;
use Doctrine\ORM\EntityManagerInterface;

class EconomicReminderSecurityTest extends ApiTestCase
{
    private function makeReminder(string $ownerCode, int $importance = 2): EconomicReminder
    {
        $owner = $this->createUser(['code' => $ownerCode, 'name' => $ownerCode]);
        $reminder = new EconomicReminder();
        $reminder->setUser($owner);
        $reminder->setEventDate('2099-12-31');
        $reminder->setEventTime('12:00');
        $reminder->setTimezone('UTC');
        $reminder->setEventTitle('Test Event ' . $ownerCode);
        $reminder->setEventTitleOriginal('Test Event ' . $ownerCode);
        $reminder->setEventCountryCode('US');
        $reminder->setEventCurrency('USD');
        $reminder->setEventImportance($importance);
        $reminder->setRemindAt(new \DateTimeImmutable('2099-12-31T11:45:00Z'));
        $this->em->persist($reminder);
        $this->em->flush();
        return $reminder;
    }

    public function testAnonymousCannotCancelReminder(): void
    {
        $reminder = $this->makeReminder('OWNER01');

        $this->client->request('POST', '/api/economic-reminders/' . $reminder->getId() . '/cancel');

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testReminderWithoutUserCodeQueryCannotBeCancelledByAnyone(): void
    {
        $reminder = $this->makeReminder('OWNER02');
        $attacker = $this->createUser(['code' => 'ATTACKER01', 'name' => 'Attacker']);
        $token = static::getContainer()->get(JwtService::class)->createToken($attacker);

        $this->client->request(
            'POST',
            '/api/economic-reminders/' . $reminder->getId() . '/cancel',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(EconomicReminder::class)->find($reminder->getId());
        $this->assertNotSame(EconomicReminder::STATUS_CANCELLED, $reloaded->getStatus(),
            'Reminder must NOT be cancelled when attacker has no user_code query');
    }

    public function testAttackerWithFakeUserCodeQueryCannotCancel(): void
    {
        $reminder = $this->makeReminder('OWNER03');
        $attacker = $this->createUser(['code' => 'ATTACKER02', 'name' => 'Attacker']);
        $token = static::getContainer()->get(JwtService::class)->createToken($attacker);

        $this->client->request(
            'POST',
            '/api/economic-reminders/' . $reminder->getId() . '/cancel?user_code=OWNER03',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(EconomicReminder::class)->find($reminder->getId());
        $this->assertNotSame(EconomicReminder::STATUS_CANCELLED, $reloaded->getStatus(),
            'Reminder must NOT be cancelled when attacker spoofs user_code query');
    }

    public function testOwnerCanCancelTheirOwnReminder(): void
    {
        $reminder = $this->makeReminder('OWNER04');
        $owner = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'OWNER04']);
        $token = static::getContainer()->get(JwtService::class)->createToken($owner);

        $this->client->request(
            'POST',
            '/api/economic-reminders/' . $reminder->getId() . '/cancel',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());

        $this->em->clear();
        $reloaded = $this->em->getRepository(EconomicReminder::class)->find($reminder->getId());
        $this->assertSame(EconomicReminder::STATUS_CANCELLED, $reloaded->getStatus());
    }

    public function testAnonymousCannotListReminders(): void
    {
        $this->client->request('GET', '/api/economic-reminders/list');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAnonymousCannotScheduleReminder(): void
    {
        $this->client->request(
            'POST',
            '/api/economic-reminders/schedule',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_date' => '2099-12-31',
                'event_time' => '12:00',
                'event_title' => 'Test',
                'event_importance' => 2,
            ])
        );
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testUserCannotScheduleReminderForAnotherUser(): void
    {
        $user = $this->createUser(['code' => 'USER01', 'name' => 'User']);
        $userId = $user->getId();
        $uniqueTitle = 'Hacked-' . bin2hex(random_bytes(4));
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'POST',
            '/api/economic-reminders/schedule',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'user_code' => 'SOMEONE_ELSE',
                'event_date' => '2099-12-31',
                'event_time' => '12:00',
                'event_title' => $uniqueTitle,
                'event_importance' => 2,
            ])
        );

        $responseBody = $this->client->getResponse()->getContent();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), 'Body: ' . $responseBody);

        $this->em->clear();
        $reminder = $this->em->getRepository(EconomicReminder::class)->findOneBy(['eventTitle' => $uniqueTitle]);
        $this->assertNotNull($reminder);

        $this->assertSame($userId, $reminder->getUser()->getId(),
            "Reminder must be owned by the AUTHENTICATED user (id=$userId), not the spoofed user_code in body. Got id=" . $reminder->getUser()->getId());
    }
}