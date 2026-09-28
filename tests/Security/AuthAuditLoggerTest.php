<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AuthAuditLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fija el contrato del audit logger de Fase 1.3 (audit AUDIT-2026-09-28 #3):
 *  - Registra CADA uso del fallback X-Game-Code/user_code.
 *  - Solo guarda los ULTIMOS 4 chars del codigo (PII minima).
 *  - Captura IP, user agent, ruta y metodo para fingerprinting.
 */
final class AuthAuditLoggerTest extends TestCase
{
    public function testLogsWithPiiMinimalSuffix(): void
    {
        $captured = [];
        $logger = new class($captured) implements LoggerInterface {
            public function __construct(private array &$captured) {}
            public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
            public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
            public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
            public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
            public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
            public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
            public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
            public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->captured[] = [(string)$level, (string)$message, $context];
            }
        };

        $request = Request::create('/api/journal', 'GET', server: ['REMOTE_ADDR' => '203.0.113.42']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 malicious-bot');

        (new AuthAuditLogger($logger))->logFallbackUsage($request, 'ABCD-1234-SECRET', 'X-Game-Code-header');

        self::assertCount(1, $captured);
        [$level, $msg, $ctx] = $captured[0];

        self::assertSame('warning', $level);
        self::assertSame('auth_bypass_fallback_used', $msg);
        self::assertSame('X-Game-Code-header', $ctx['source']);
        self::assertSame('203.0.113.42', $ctx['ip']);
        self::assertSame('Mozilla/5.0 malicious-bot', $ctx['user_agent']);
        self::assertSame('GET', $ctx['method']);
        self::assertSame('/api/journal', $ctx['path']);
        // PII: solo ultimos 4 chars
        self::assertSame('CRET', $ctx['code_suffix']); // last 4 of 'ABCD-1234-SECRET'
    }

    public function testHandlesEmptyCodeGracefully(): void
    {
        $captured = [];
        $logger = new class($captured) implements LoggerInterface {
            public function __construct(private array &$captured) {}
            public function emergency(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function alert(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function critical(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function error(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function warning(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function notice(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function info(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function debug(string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
            public function log($l, string|\Stringable $m, array $c = []): void { $this->captured[] = $c; }
        };

        $request = Request::create('/api/feed', 'POST');
        (new AuthAuditLogger($logger))->logFallbackUsage($request, '', 'query-string');

        self::assertCount(1, $captured);
        self::assertSame('', $captured[0]['code_suffix']);
    }
}