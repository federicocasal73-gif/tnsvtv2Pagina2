<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Outbound mail for auth flows (2FA codes, enrollment, password reset).
 *
 * Transport comes from MAILER_DSN (Brevo SMTP in prod). When the DSN is
 * null:// (local dev + tests), mails are dumped to var/mailbox/*.json
 * instead of sent — the whole flow stays testable without credentials.
 */
class AppMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    private function isMailboxMode(): bool
    {
        $dsn = $_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? 'null://null';

        return str_starts_with((string) $dsn, 'null://');
    }

    private function fromAddress(): string
    {
        $from = $_ENV['MAIL_FROM'] ?? $_SERVER['MAIL_FROM'] ?? '';

        return '' !== trim((string) $from) ? trim((string) $from) : 'noreply@tnsvt.com';
    }

    /**
     * @return bool true if handed to the transport (or mailbox), false on error.
     */
    public function sendCode(string $to, string $code, string $purpose): bool
    {
        $subjects = [
            'login' => 'Tu código de acceso al Sanctum',
            'enroll' => 'Verificá tu mail del Sanctum',
            'reset' => 'Recuperá tu contraseña del Sanctum',
        ];
        $subject = ($subjects[$purpose] ?? $subjects['login']) . ' · ' . $code;

        try {
            $html = $this->twig->render('mail/auth_code.html.twig', [
                'code' => $code,
                'purpose' => $purpose,
                'minutes' => 10,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[MAIL] template render failed, using fallback', ['error' => $e->getMessage()]);
            $html = '<p>Tu código es <strong>' . htmlspecialchars($code, ENT_QUOTES) . '</strong> (válido 10 minutos).</p>';
        }
        $text = "Tu código del Sanctum es {$code} (válido 10 minutos). Si no fuiste vos, ignorá este mail.";

        if ($this->isMailboxMode()) {
            return $this->dumpToMailbox($to, $subject, $text, $code, $purpose);
        }

        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->fromAddress())
                    ->to($to)
                    ->subject($subject)
                    ->text($text)
                    ->html($html)
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[MAIL] send failed', ['to' => $this->mask($to), 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function dumpToMailbox(string $to, string $subject, string $text, string $code, string $purpose): bool
    {
        try {
            $dir = $this->projectDir . '/var/mailbox';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $file = sprintf('%s/%s-%s.json', $dir, date('YmdHis'), bin2hex(random_bytes(4)));
            file_put_contents($file, json_encode([
                'to' => $to,
                'subject' => $subject,
                'code' => $code,
                'purpose' => $purpose,
                'at' => (new \DateTimeImmutable())->format('c'),
            ], JSON_THROW_ON_ERROR));
            $this->logger->info('[MAIL] dumped to mailbox (null transport)', ['file' => basename($file)]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[MAIL] mailbox dump failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function mask(string $email): string
    {
        $parts = explode('@', $email);
        if (2 !== \count($parts)) {
            return '***';
        }

        return substr($parts[0], 0, 1) . '***@' . $parts[1];
    }
}
