<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TwoFactorChallenge;
use App\Entity\User;

/**
 * End-to-end 2FA por mail + recupero de contraseña.
 *
 * TwoFactorService lee TWO_FACTOR_MODE / TWO_FACTOR_GRACE_UNTIL de $_ENV,
 * así que cada test fija y restaura el entorno (default: disabled).
 * Los mails caen en var/mailbox/*.json (transporte null en test).
 */
class TwoFactorTest extends ApiTestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEnv = [$_ENV['TWO_FACTOR_MODE'] ?? null, $_ENV['TWO_FACTOR_GRACE_UNTIL'] ?? null];
        foreach (glob($this->mailboxDir() . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        // Rate limiters propios (herméticos entre runs).
        $rl = static::getContainer()->get(\App\Service\RateLimiterService::class);
        $rl->reset('2fa_enroll:127.0.0.1');
        $rl->reset('pwd_forgot:127.0.0.1');
    }

    protected function tearDown(): void
    {
        [$mode, $grace] = $this->savedEnv + [null, null];
        if (null === $mode) {
            unset($_ENV['TWO_FACTOR_MODE']);
        } else {
            $_ENV['TWO_FACTOR_MODE'] = $mode;
        }
        if (null === $grace) {
            unset($_ENV['TWO_FACTOR_GRACE_UNTIL']);
        } else {
            $_ENV['TWO_FACTOR_GRACE_UNTIL'] = $grace;
        }
        parent::tearDown();
    }

    private function mailboxDir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/var/mailbox';
    }

    private function mandatory(): void
    {
        $_ENV['TWO_FACTOR_MODE'] = 'mandatory';
        $_ENV['TWO_FACTOR_GRACE_UNTIL'] = '2000-01-01'; // gracia vencida
    }

    private function lastMailboxCode(): ?string
    {
        $files = glob($this->mailboxDir() . '/*.json') ?: [];
        if ([] === $files) {
            return null;
        }
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $data = json_decode((string) file_get_contents($files[0]), true);

        return is_array($data) ? ($data['code'] ?? null) : null;
    }

    private function enrolledUser(string $code): User
    {
        $user = $this->createUser(['code' => $code, 'name' => 'Twofa User', 'email' => $code . '@x.com']);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setTwoFactorEnabled(true);
        $this->em->flush();

        return $user;
    }

    public function testLoginIssuesChallengeWhenEnforced(): void
    {
        $this->mandatory();
        $this->enrolledUser('TFA01');

        $r = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA01', 'name' => 'Twofa User', 'password' => 'TestPassword123!']);

        $this->assertSame(200, $r['status']);
        $this->assertFalse($r['data']['success']);
        $this->assertSame('two_factor_required', $r['data']['error_code']);
        $this->assertNotEmpty($r['data']['challenge_id']);
        $this->assertStringContainsString('@x.com', $r['data']['masked_email']);
        $this->assertArrayNotHasKey('token', $r['data']);
        // El mail llegó al mailbox local con el código.
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->lastMailboxCode());
    }

    public function testVerifyCompletesLogin(): void
    {
        $this->mandatory();
        $this->enrolledUser('TFA02');

        $login = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA02', 'name' => 'Twofa User', 'password' => 'TestPassword123!']);
        $code = (string) $this->lastMailboxCode();
        $this->assertNotEmpty($code);

        $r = $this->jsonRequest('POST', '/api/auth/2fa/verify', [
            'challenge_id' => $login['data']['challenge_id'],
            'code' => $code,
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertNotEmpty($r['data']['token']);
        $this->assertNotEmpty($r['data']['refresh_token']);

        // Un solo uso: reintentar el mismo código falla.
        $again = $this->jsonRequest('POST', '/api/auth/2fa/verify', [
            'challenge_id' => $login['data']['challenge_id'],
            'code' => $code,
        ]);
        $this->assertSame(410, $again['status']);
    }

    public function testWrongCodeDecrementsAndLocks(): void
    {
        $this->mandatory();
        $this->enrolledUser('TFA03');

        $login = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA03', 'name' => 'Twofa User', 'password' => 'TestPassword123!']);
        $cid = $login['data']['challenge_id'];

        for ($i = 4; $i >= 1; $i--) {
            $r = $this->jsonRequest('POST', '/api/auth/2fa/verify', ['challenge_id' => $cid, 'code' => '000000']);
            $this->assertSame(401, $r['status']);
            $this->assertSame($i, $r['data']['attempts_left']);
        }
        // Quinto fallo: agotados.
        $last = $this->jsonRequest('POST', '/api/auth/2fa/verify', ['challenge_id' => $cid, 'code' => '000000']);
        $this->assertSame(401, $last['status']);
        $this->assertSame(0, $last['data']['attempts_left']);
    }

    public function testExpiredChallengeReturns410(): void
    {
        $this->mandatory();
        $user = $this->enrolledUser('TFA04');

        $challenge = (new TwoFactorChallenge())
            ->setUser($user)
            ->setPurpose(TwoFactorChallenge::PURPOSE_LOGIN)
            ->setCodeHash(hash('sha256', '123456'))
            ->setExpiresAt(new \DateTimeImmutable('-1 minute'))
            ->markSent();
        $this->em->persist($challenge);
        $this->em->flush();

        $r = $this->jsonRequest('POST', '/api/auth/2fa/verify', [
            'challenge_id' => $challenge->getId(),
            'code' => '123456',
        ]);

        $this->assertSame(410, $r['status']);
        $this->assertSame('challenge_expired', $r['data']['error_code']);
    }

    public function testResendCooldown(): void
    {
        $this->mandatory();
        $this->enrolledUser('TFA05');

        $login = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA05', 'name' => 'Twofa User', 'password' => 'TestPassword123!']);

        // Reenvío inmediato: cooldown 60s.
        $r = $this->jsonRequest('POST', '/api/auth/2fa/resend', ['challenge_id' => $login['data']['challenge_id']]);

        $this->assertSame(429, $r['status']);
        $this->assertGreaterThan(0, $r['data']['retry_after']);
    }

    public function testEnrollmentRequiredAfterGrace(): void
    {
        $this->mandatory();
        $this->createUser(['code' => 'TFA06', 'name' => 'No Mail']);

        $r = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA06', 'name' => 'No Mail', 'password' => 'TestPassword123!']);

        $this->assertSame(200, $r['status']);
        $this->assertSame('enrollment_required', $r['data']['error_code']);
        $this->assertArrayNotHasKey('token', $r['data']);
    }

    public function testGraceAllowsLoginWithWarning(): void
    {
        $_ENV['TWO_FACTOR_MODE'] = 'mandatory';
        $_ENV['TWO_FACTOR_GRACE_UNTIL'] = '2099-01-01';
        $this->createUser(['code' => 'TFA07', 'name' => 'Grace User']);

        $r = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA07', 'name' => 'Grace User', 'password' => 'TestPassword123!']);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertNotEmpty($r['data']['token']);
        $this->assertNotEmpty($r['data']['enrollment_warning']['message']);
    }

    public function testEnrollFlowCompletesLogin(): void
    {
        $this->mandatory();
        $this->createUser(['code' => 'TFA08', 'name' => 'Enroll Me']);

        $start = $this->jsonRequest('POST', '/api/auth/2fa/enroll/start', [
            'code' => 'TFA08',
            'email' => 'enrollme@x.com',
        ]);
        $this->assertSame(200, $start['status']);
        $this->assertStringContainsString('@x.com', $start['data']['masked_email']);

        $code = (string) $this->lastMailboxCode();
        $verify = $this->jsonRequest('POST', '/api/auth/2fa/verify', [
            'challenge_id' => $start['data']['challenge_id'],
            'code' => $code,
        ]);

        $this->assertSame(200, $verify['status']);
        $this->assertTrue($verify['data']['success']);
        $this->assertTrue($verify['data']['enrolled']);
        $this->assertNotEmpty($verify['data']['token']);

        $user = $this->em->getRepository(User::class)->findOneBy(['code' => 'TFA08']);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($user->isTwoFactorEnabled());
    }

    public function testForgotResetFlow(): void
    {
        $this->mandatory();
        $user = $this->enrolledUser('TFA09');

        $forgot = $this->jsonRequest('POST', '/api/auth/password/forgot', ['code' => 'TFA09']);
        $this->assertSame(200, $forgot['status']);
        $this->assertTrue($forgot['data']['success']);

        // Código inexistente: misma respuesta genérica (no enumera).
        $ghost = $this->jsonRequest('POST', '/api/auth/password/forgot', ['code' => 'NOEXISTE']);
        $this->assertSame(200, $ghost['status']);

        $code = (string) $this->lastMailboxCode();
        $challenge = $this->em->getRepository(TwoFactorChallenge::class)->findOneBy(
            ['user' => $user->getId()],
            ['id' => 'DESC']
        );

        $reset = $this->jsonRequest('POST', '/api/auth/password/reset', [
            'challenge_id' => $challenge->getId(),
            'code' => $code,
            'new_password' => 'NuevaClave123',
        ]);
        $this->assertSame(200, $reset['status']);

        // La nueva password ahora es exigida en el login (mandatory+challenge).
        $login = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'TFA09',
            'name' => 'Twofa User',
            'password' => 'NuevaClave123',
        ]);
        $this->assertSame('two_factor_required', $login['data']['error_code'] ?? null);
    }

    public function testExemptUserSkipsChallenge(): void
    {
        $this->mandatory();
        $user = $this->enrolledUser('TFA10');
        $user->setTwoFactorExempt(true);
        $this->em->flush();

        $r = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA10', 'name' => 'Twofa User', 'password' => 'TestPassword123!']);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertNotEmpty($r['data']['token']);
    }

    public function testResetByCodeFlow(): void
    {
        $this->mandatory();
        $this->enrolledUser('TFA12');
        $this->rateLimitReset('pwd_forgot:127.0.0.1');

        $this->jsonRequest('POST', '/api/auth/password/forgot', ['code' => 'TFA12']);
        $code = (string) $this->lastMailboxCode();
        $this->assertNotEmpty($code);

        $r = $this->jsonRequest('POST', '/api/auth/password/reset-by-code', [
            'code' => 'TFA12',
            'email_code' => $code,
            'new_password' => 'OtraClave123',
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);

        // La nueva password rige el login.
        $bad = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'TFA12', 'name' => 'Twofa User', 'password' => 'TestPassword123!',
        ]);
        $this->assertSame(401, $bad['status']);
    }

    public function testProfileEmailAndPassword(): void
    {
        $user = $this->createUser(['code' => 'TFA13', 'name' => 'Profile User']);
        $this->loginAs($user);

        $send = $this->jsonRequest('POST', '/api/profile/email', ['email' => 'profile@x.com']);
        $this->assertSame(200, $send['status']);
        $this->assertNotEmpty($send['data']['challenge_id']);

        $code = (string) $this->lastMailboxCode();
        $verify = $this->jsonRequest('POST', '/api/profile/email/verify', [
            'challenge_id' => $send['data']['challenge_id'],
            'code' => $code,
        ]);
        $this->assertSame(200, $verify['status']);
        $this->assertTrue($verify['data']['success']);

        // El helper crea usuarios CON password: cambiarla exige la actual
        // + código fresco al mail verificado.
        $codeReq = $this->jsonRequest('POST', '/api/profile/password/code');
        $this->assertSame(200, $codeReq['status']);
        $passCode = (string) $this->lastMailboxCode();
        $this->assertNotEmpty($passCode);

        $pass = $this->jsonRequest('POST', '/api/profile/password', [
            'current_password' => 'TestPassword123!',
            'new_password' => 'PerfilClave12',
            'email_code' => $passCode,
        ]);
        $this->assertSame(200, $pass['status']);

        // Cambiarla exige la actual (el código válido se consume al verificar).
        $codeReq2 = $this->jsonRequest('POST', '/api/profile/password/code');
        $this->assertSame(200, $codeReq2['status']);
        $wrong = $this->jsonRequest('POST', '/api/profile/password', [
            'current_password' => 'nope',
            'new_password' => 'OtraClave1234',
            'email_code' => (string) $this->lastMailboxCode(),
        ]);
        $this->assertSame(401, $wrong['status']);

        // El código es de un solo uso: pedir otro para el cambio válido.
        $codeReq3 = $this->jsonRequest('POST', '/api/profile/password/code');
        $this->assertSame(200, $codeReq3['status']);
        $ok = $this->jsonRequest('POST', '/api/profile/password', [
            'current_password' => 'PerfilClave12',
            'new_password' => 'OtraClave1234',
            'email_code' => (string) $this->lastMailboxCode(),
        ]);
        $this->assertSame(200, $ok['status']);
    }

    public function testAdminSecurityPatch(): void
    {
        $admin = $this->createAdmin(['code' => 'TFAADM']);
        $this->createUser(['code' => 'TFA14', 'name' => 'Managed']);
        $this->loginAs($admin);

        $r = $this->jsonRequest('PATCH', '/sanctum/api/users/TFA14/security', [
            'email' => 'managed@x.com',
            'two_factor_exempt' => true,
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);
        $this->assertSame('managed@x.com', $r['data']['user']['email']);
        $this->assertTrue($r['data']['user']['two_factor_exempt']);
        $this->assertFalse($r['data']['user']['email_verified']);

        $plain = $this->createUser(['code' => 'TFA15', 'name' => 'Plain']);
        $this->loginAs($plain);
        $denied = $this->jsonRequest('PATCH', '/sanctum/api/users/TFA14/security', ['two_factor_exempt' => false]);
        $this->assertSame(403, $denied['status']);
    }

    private function rateLimitReset(string $key): void
    {
        static::getContainer()->get(\App\Service\RateLimiterService::class)->reset($key);
    }

    public function testPasswordRequiredWhenSet(): void
    {
        $this->createUser(['code' => 'TFA11', 'name' => 'Pass User', 'password' => 'ClaveCorrecta1']);

        // Sin password → 401 aunque el nombre esté bien.
        $noPass = $this->jsonRequest('POST', '/api/auth/login', ['code' => 'TFA11', 'name' => 'Pass User']);
        $this->assertSame(401, $noPass['status']);

        // Con password correcta → login (modo disabled por default).
        $ok = $this->jsonRequest('POST', '/api/auth/login', [
            'code' => 'TFA11',
            'name' => 'Pass User',
            'password' => 'ClaveCorrecta1',
        ]);
        $this->assertSame(200, $ok['status']);
        $this->assertTrue($ok['data']['success']);
    }
}
