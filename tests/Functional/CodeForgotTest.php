<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;

/**
 * Recupero self-service del código de adepto por mail verificado.
 *
 * POST /api/auth/code/forgot responde genérico siempre (no enumera):
 * solo envía cuando el mail existe, el usuario está activo y el mail
 * está verificado. El payload del dump ES el código (permanente, no expira).
 */
class CodeForgotTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob($this->mailboxDir() . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        static::getContainer()->get(\App\Service\RateLimiterService::class)->reset('code_forgot:127.0.0.1');
    }

    private function mailboxDir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/var/mailbox';
    }

    /**
     * Lector propio: el payload de recupero es el código de adepto
     * (p.ej. "JUAN01"), no un 6-dígitos como en TwoFactorTest.
     *
     * @return list<array<string, mixed>>
     */
    private function mailboxDumps(): array
    {
        $files = glob($this->mailboxDir() . '/*.json') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach ($files as $f) {
            $data = json_decode((string) file_get_contents($f), true);
            if (\is_array($data)) {
                $out[] = $data;
            }
        }

        return $out;
    }

    private function verifiedUser(string $code, string $email): User
    {
        $user = $this->createUser(['code' => $code, 'name' => 'Code Forgot', 'email' => $email]);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $user;
    }

    public function testVerifiedEmailReceivesCode(): void
    {
        $this->verifiedUser('CF01', 'cf01@x.com');

        $r = $this->jsonRequest('POST', '/api/auth/code/forgot', ['email' => 'cf01@x.com']);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertSame('Si el mail existe y está verificado, enviamos tu código.', $r['data']['message']);

        $dumps = $this->mailboxDumps();
        $this->assertCount(1, $dumps);
        $this->assertSame('cf01@x.com', $dumps[0]['to']);
        $this->assertSame('CF01', $dumps[0]['code']);
        $this->assertSame('code', $dumps[0]['purpose']);
    }

    public function testUnknownEmailSendsNothing(): void
    {
        $r = $this->jsonRequest('POST', '/api/auth/code/forgot', ['email' => 'nadie@x.com']);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertSame([], $this->mailboxDumps());
    }

    public function testUnverifiedEmailSendsNothing(): void
    {
        $this->createUser(['code' => 'CF02', 'name' => 'No Verify', 'email' => 'cf02@x.com']);

        $r = $this->jsonRequest('POST', '/api/auth/code/forgot', ['email' => 'cf02@x.com']);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertSame([], $this->mailboxDumps());
    }

    public function testInactiveUserSendsNothing(): void
    {
        $user = $this->verifiedUser('CF03', 'cf03@x.com');
        $user->setActive(false);
        $this->em->flush();

        $r = $this->jsonRequest('POST', '/api/auth/code/forgot', ['email' => 'cf03@x.com']);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertSame([], $this->mailboxDumps());
    }

    public function testRateLimitedOnSixthAttempt(): void
    {
        $this->verifiedUser('CF04', 'cf04@x.com');

        for ($i = 0; $i < 5; ++$i) {
            $r = $this->jsonRequest('POST', '/api/auth/code/forgot', ['email' => 'cf04@x.com']);
            $this->assertSame(200, $r['status']);
        }

        $blocked = $this->jsonRequest('POST', '/api/auth/code/forgot', ['email' => 'cf04@x.com']);
        $this->assertSame(429, $blocked['status']);
        $this->assertFalse($blocked['data']['success']);
    }
}
