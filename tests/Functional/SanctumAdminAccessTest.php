<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Auth\JwtService;
use PHPUnit\Framework\Attributes\DataProvider;

class SanctumAdminAccessTest extends ApiTestCase
{
    public static function adminOnlyEndpoints(): array
    {
        return [
            ['GET', '/sanctum/api/audit'],
            ['GET', '/sanctum/api/settings'],
            ['GET', '/sanctum/api/dashboard'],
            ['GET', '/sanctum/api/users'],
            ['GET', '/sanctum/api/monitoring/status'],
            ['GET', '/sanctum/api/oracle/global-stats'],
        ];
    }

    #[DataProvider('adminOnlyEndpoints')]
    public function testAnonymousRequestIsRejected(string $method, string $path): void
    {
        $this->client->request($method, $path);

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), "Anonymous request to {$method} {$path} must return 401");
    }

    #[DataProvider('adminOnlyEndpoints')]
    public function testRegularUserIsForbidden(string $method, string $path): void
    {
        $user = $this->createUser(['code' => 'NORMAL01', 'name' => 'Regular User']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            $method,
            $path,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), "Regular user request to {$method} {$path} must return 403");
    }

    #[DataProvider('adminOnlyEndpoints')]
    public function testAdminCanAccess(string $method, string $path): void
    {
        $admin = $this->createAdmin(['code' => 'ADM001', 'name' => 'Admin User']);
        $token = static::getContainer()->get(JwtService::class)->createToken($admin);

        $this->client->request(
            $method,
            $path,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        $this->assertNotSame(401, $response->getStatusCode(), "Admin must not get 401 on {$method} {$path}");
        $this->assertNotSame(403, $response->getStatusCode(), "Admin must not get 403 on {$method} {$path}");
    }

    public function testAdminCanChangeUserTier(): void
    {
        $admin = $this->createAdmin(['code' => 'ADM002']);
        $target = $this->createUser(['code' => 'TARGET01', 'name' => 'Target']);
        $token = static::getContainer()->get(JwtService::class)->createToken($admin);

        $this->client->request(
            'PATCH',
            '/sanctum/api/users/TARGET01/tier',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['tier' => 'TIER_1']),
        );

        $response = $this->client->getResponse();
        $body = $response->getContent();
        $this->assertSame(200, $response->getStatusCode(), 'Response: ' . $body);
        $data = json_decode($body, true);
        $this->assertSame('INITIATE', $data['oldTier'] ?? null);
        $this->assertSame('TIER_1', $data['newTier'] ?? null);
    }

    public function testRegularUserCannotChangeUserTier(): void
    {
        $user = $this->createUser(['code' => 'EVIL01', 'name' => 'Evil User']);
        $target = $this->createUser(['code' => 'VICTIM01', 'name' => 'Victim']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'PATCH',
            '/sanctum/api/users/VICTIM01/tier',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['tier' => 'MASTER']),
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'VICTIM01']);
        $this->assertNotSame('MASTER', $reloaded->getTier(), 'Tier must NOT have changed');
    }

    public function testRegularUserCannotDeactivateAnotherUser(): void
    {
        $attacker = $this->createUser(['code' => 'ATTACKER', 'name' => 'Attacker']);
        $victim = $this->createUser(['code' => 'VICTIM02', 'name' => 'Victim 2']);
        $token = static::getContainer()->get(JwtService::class)->createToken($attacker);

        $this->client->request(
            'PATCH',
            '/sanctum/api/users/VICTIM02/active',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'VICTIM02']);
        $this->assertTrue($reloaded->isActive(), 'Victim must remain active');
    }

    public function testInvalidTierIsRejectedForAdmin(): void
    {
        $admin = $this->createAdmin(['code' => 'ADM003']);
        $target = $this->createUser(['code' => 'TARGET02']);
        $token = static::getContainer()->get(JwtService::class)->createToken($admin);

        $this->client->request(
            'PATCH',
            '/sanctum/api/users/TARGET02/tier',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['tier' => 'EVIL_TIER_NOT_IN_LIST']),
        );

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid tier', $data['error'] ?? null);
    }

    public function testAdminCanCreateUserViaSession(): void
    {
        // Browser path: session cookie (loginUser), no JWT header.
        $admin = $this->createAdmin(['code' => 'ADMCRT']);
        $this->loginAs($admin);

        $this->client->request(
            'POST',
            '/api/admin/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => 'NEWBIE01', 'name' => 'Newbie']),
        );

        $response = $this->client->getResponse();
        $body = $response->getContent();
        $this->assertSame(201, $response->getStatusCode(), 'Response: ' . $body);
        $data = json_decode($body, true);
        $this->assertSame('NEWBIE01', $data['code'] ?? null);

        $this->em->clear();
        $created = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'NEWBIE01']);
        $this->assertNotNull($created, 'User must have been persisted');
        $this->assertTrue($created->isActive());
    }

    public function testCreateUserDuplicateCodeReturns409(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMCRT2']);
        $this->createUser(['code' => 'DUPE01', 'name' => 'Existing']);
        $this->loginAs($admin);

        $this->client->request(
            'POST',
            '/api/admin/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => 'dupe01', 'name' => 'Duplicate']),
        );

        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testRegularUserCannotCreateUser(): void
    {
        $user = $this->createUser(['code' => 'EVILCRT', 'name' => 'Evil Creator']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'POST',
            '/api/admin/users',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['code' => 'SHOULDNOT', 'name' => 'Should Not Exist']),
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $ghost = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'SHOULDNOT']);
        $this->assertNull($ghost, 'User must NOT have been created');
    }

    public function testAdminCanDeleteFreshUser(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMDEL']);
        $this->createUser(['code' => 'DOOMED01', 'name' => 'Doomed']);
        $this->loginAs($admin);

        $this->client->request('DELETE', '/sanctum/api/users/DOOMED01');

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), 'Response: ' . $response->getContent());

        $this->em->clear();
        $gone = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'DOOMED01']);
        $this->assertNull($gone, 'User must have been deleted');
    }

    public function testDeleteMissingUserReturns404(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMDEL2']);
        $this->loginAs($admin);

        $this->client->request('DELETE', '/sanctum/api/users/NOEXISTE99');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $admin = $this->createAdmin(['code' => 'ADMSELF']);
        $this->loginAs($admin);

        $this->client->request('DELETE', '/sanctum/api/users/ADMSELF');

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode(), 'Response: ' . $response->getContent());

        $this->em->clear();
        $stillThere = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'ADMSELF']);
        $this->assertNotNull($stillThere, 'Admin must NOT have deleted themselves');
    }

    public function testRegularUserCannotDeleteUser(): void
    {
        $user = $this->createUser(['code' => 'EVILDEL', 'name' => 'Evil Deleter']);
        $victim = $this->createUser(['code' => 'VICTIMDEL', 'name' => 'Victim Del']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'DELETE',
            '/sanctum/api/users/VICTIMDEL',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $survivor = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'VICTIMDEL']);
        $this->assertNotNull($survivor, 'Victim must remain');
    }
}