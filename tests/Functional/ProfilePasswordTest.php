<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;

/**
 * Cambio de contraseña en perfil exige código fresco al mail verificado.
 *
 * POST /api/profile/password/code emite challenge RESET al propio mail;
 * POST /api/profile/password exige email_code además de la actual.
 * Sin mail verificado no hay código posible (400) y sin challenge no
 * hay cambio (410). Mitiga session hijacking.
 */
class ProfilePasswordTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob($this->mailboxDir() . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function mailboxDir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/var/mailbox';
    }

    private function lastResetCode(): ?string
    {
        $files = glob($this->mailboxDir() . '/*.json') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach ($files as $f) {
            $data = json_decode((string) file_get_contents($f), true);
            if (\is_array($data) && 'reset' === ($data['purpose'] ?? null)) {
                return $data['code'] ?? null;
            }
        }

        return null;
    }

    private function mailboxCount(): int
    {
        return \count(glob($this->mailboxDir() . '/*.json') ?: []);
    }

    private function verifiedUser(string $code, string $email): User
    {
        $user = $this->createUser(['code' => $code, 'name' => 'Pass Change', 'email' => $email]);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->loginAs($user);
        static::getContainer()->get(\App\Service\RateLimiterService::class)->reset('profile_pwd_code:' . $code);

        return $user;
    }

    public function testSendCodeNeedsVerifiedEmail(): void
    {
        $user = $this->createUser(['code' => 'PP01', 'name' => 'No Mail', 'email' => 'pp01@x.com']);
        $this->loginAs($user);

        $r = $this->jsonRequest('POST', '/api/profile/password/code');

        $this->assertSame(400, $r['status']);
        $this->assertSame(0, $this->mailboxCount());
    }

    public function testSendCodeDeliversToOwnMail(): void
    {
        $this->verifiedUser('PP02', 'pp02@x.com');

        $r = $this->jsonRequest('POST', '/api/profile/password/code');

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->lastResetCode());
    }

    public function testChangeWithoutCodeIsRejected(): void
    {
        $this->verifiedUser('PP03', 'pp03@x.com');

        $r = $this->jsonRequest('POST', '/api/profile/password', [
            'current_password' => 'TestPassword123!',
            'new_password' => 'OtraClave1234',
        ]);

        $this->assertSame(410, $r['status']);
        $this->assertSame('challenge_expired', $r['data']['error_code']);
    }

    public function testChangeWithWrongCodeIsRejected(): void
    {
        $this->verifiedUser('PP04', 'pp04@x.com');
        $this->jsonRequest('POST', '/api/profile/password/code');

        $r = $this->jsonRequest('POST', '/api/profile/password', [
            'current_password' => 'TestPassword123!',
            'new_password' => 'OtraClave1234',
            'email_code' => '000000',
        ]);

        $this->assertSame(401, $r['status']);
        $this->assertSame(4, $r['data']['attempts_left']);
    }

    public function testChangeWithValidCodeSucceeds(): void
    {
        $this->verifiedUser('PP05', 'pp05@x.com');
        $this->jsonRequest('POST', '/api/profile/password/code');
        $code = (string) $this->lastResetCode();
        $this->assertNotEmpty($code);

        $r = $this->jsonRequest('POST', '/api/profile/password', [
            'current_password' => 'TestPassword123!',
            'new_password' => 'PerfilClave12',
            'email_code' => $code,
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);

        // La nueva rige el login (modo disabled por default: entra directo).
        $login = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'PP05', 'name' => 'Pass Change', 'password' => 'PerfilClave12',
        ]);
        $this->assertSame(200, $login['status']);
        $this->assertTrue($login['data']['success']);
    }

    public function testSendCodeRateLimited(): void
    {
        $this->verifiedUser('PP06', 'pp06@x.com');

        for ($i = 0; $i < 5; ++$i) {
            $r = $this->jsonRequest('POST', '/api/profile/password/code');
            $this->assertSame(200, $r['status']);
        }

        $blocked = $this->jsonRequest('POST', '/api/profile/password/code');
        $this->assertSame(429, $blocked['status']);
    }
}
