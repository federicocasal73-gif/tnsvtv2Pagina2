<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFactoryTest extends ApiTestCase
{
    public function testCreateUserPersistsToDatabase(): void
    {
        $user = $this->createUser([
            'code' => 'TEST001',
            'name' => 'Factory Test User',
            'email' => 'factory@test.local',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('TEST001', $user->getCode());
        $this->assertSame('Factory Test User', $user->getName());
        $this->assertTrue($user->isActive());

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->findOneBy(['code' => 'TEST001']);
        $this->assertNotNull($reloaded);
        $this->assertSame('Factory Test User', $reloaded->getName());
    }

    public function testCreateAdminHasRoleAdmin(): void
    {
        $admin = $this->createAdmin([
            'code' => 'ADMIN01',
            'name' => 'Factory Admin',
        ]);

        $this->assertContains('ROLE_ADMIN', $admin->getRoles());
        $this->assertContains('ROLE_USER', $admin->getRoles());
    }

    public function testPasswordIsHashedNotStoredPlain(): void
    {
        $user = $this->createUser([
            'code' => 'HASHPW',
            'password' => 'PlainPassword123',
        ]);

        $this->assertNotSame('PlainPassword123', $user->getPassword());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, 'PlainPassword123'));
        $this->assertFalse($hasher->isPasswordValid($user, 'WrongPassword'));
    }
}