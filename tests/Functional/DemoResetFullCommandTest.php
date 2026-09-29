<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\JournalEntry;
use App\Entity\Message;
use App\Entity\TradingAccount;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the destructive app:demo:reset-full command (runs in-process
 * against the sqlite test DB).
 */
class DemoResetFullCommandTest extends ApiTestCase
{
    private function tester(): CommandTester
    {
        $app = new Application(static::$kernel ?? self::createKernel());

        return new CommandTester($app->find('app:demo:reset-full'));
    }

    private function countRows(string $class): int
    {
        return \count($this->em->getRepository($class)->findAll());
    }

    public function testRefusesWithoutForce(): void
    {
        $this->createAdmin(['code' => 'ADM99']);

        $exit = $this->tester()->execute([]);

        $this->assertSame(1, $exit);
    }

    public function testAbortsWhenNoAdminExists(): void
    {
        $this->createUser(['code' => 'PLAIN01']);

        $exit = $this->tester()->execute(['--force' => true]);

        $this->assertSame(1, $exit);
        // Non-admin must survive the abort.
        $this->assertNotNull($this->em->getRepository(User::class)->findOneBy(['code' => 'PLAIN01']));
    }

    public function testWipesAndSeedsDemo(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMRESET']);
        $victim = $this->createUser(['code' => 'GONE01']);

        $exit = $this->tester()->execute(['--force' => true, '--demo-password' => 'DemoTest123!']);

        $this->assertSame(0, $exit);

        $codes = array_map(
            static fn (User $u) => $u->getCode(),
            $this->em->getRepository(User::class)->findAll()
        );
        sort($codes);
        $this->assertSame(['ADMRESET', 'DEMO01'], $codes);
        $this->assertNull($this->em->getRepository(User::class)->find($victim->getId()));

        $this->assertSame(1, $this->countRows(TradingAccount::class));
        $this->assertSame(1, $this->countRows(Conversation::class));
        $this->assertSame(2, $this->countRows(Message::class));
        $this->assertSame(0, $this->countRows(JournalEntry::class));

        $demo = $this->em->getRepository(User::class)->findOneBy(['code' => 'DEMO01']);
        $this->assertNotNull($demo);
        $this->assertTrue($demo->isActive());
        $this->assertContains('ROLE_USER', $demo->getRoles());

        // Admin stayed untouched.
        $this->assertSame($admin->getId(), $this->em->getRepository(User::class)->findOneBy(['code' => 'ADMRESET'])?->getId());
    }
}
