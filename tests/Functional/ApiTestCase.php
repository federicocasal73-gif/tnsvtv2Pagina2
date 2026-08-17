<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->resetDatabaseState();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->close();
        }
        parent::tearDown();
    }

    protected function resetDatabaseState(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->tablesToTruncate() as $table) {
            try {
                $conn->executeStatement('DELETE FROM ' . $table);
            } catch (\Throwable) {
            }
        }
    }

    protected function tablesToTruncate(): array
    {
        return ['user', 'users', 'economic_reminders', 'admin_audit_log', 'wallet_transactions'];
    }

    protected function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    protected function createUser(array $overrides = []): User
    {
        $user = new User();
        $user->setCode($overrides['code'] ?? 'TEST' . random_int(1000, 9999));
        $user->setName($overrides['name'] ?? 'Test User');
        $user->setEmail($overrides['email'] ?? null);
        $user->setActive($overrides['active'] ?? true);
        $user->setTier($overrides['tier'] ?? 'INITIATE');
        $user->setRoles($overrides['roles'] ?? ['ROLE_USER']);
        $user->setPassword(
            $this->hasher()->hashPassword($user, $overrides['password'] ?? 'TestPassword123!')
        );

        if (array_key_exists('coins', $overrides)) {
            $user->setCoins((int) $overrides['coins']);
        }
        if (array_key_exists('walletBalance', $overrides)) {
            $user->setWalletBalance((string) $overrides['walletBalance']);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createAdmin(array $overrides = []): User
    {
        return $this->createUser(array_merge($overrides, [
            'code' => $overrides['code'] ?? 'ADMIN01',
            'name' => $overrides['name'] ?? 'Test Admin',
            'roles' => ['ROLE_ADMIN', 'ROLE_USER'],
        ]));
    }

    protected function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }

    protected function jsonRequest(string $method, string $uri, array $body = []): array
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );

        return $this->parseJsonResponse();
    }

    protected function parseJsonResponse(): array
    {
        $response = $this->client->getResponse();
        $content = $response->getContent();

        return [
            'status' => $response->getStatusCode(),
            'ok' => $response->isSuccessful(),
            'data' => $content === '' ? null : json_decode($content, true),
        ];
    }
}