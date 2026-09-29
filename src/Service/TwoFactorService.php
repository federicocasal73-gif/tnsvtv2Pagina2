<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TwoFactorChallenge;
use App\Entity\User;
use App\Repository\TwoFactorChallengeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email-code second factor: issue / verify / resend challenges for login,
 * email enrollment and password recovery.
 *
 * Modes (TWO_FACTOR_MODE): disabled | optin | mandatory.
 * - optin: enforced for admins, optional toggle for users.
 * - mandatory: enforced for everyone with a verified email; users without
 *   one get an enrollment flow until TWO_FACTOR_GRACE_UNTIL (Y-m-d), full
 *   login + warning before that date so nobody gets locked out on day one.
 * Exempt service accounts (e2e/APK) skip the challenge entirely.
 */
class TwoFactorService
{
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const MAX_RESENDS = 3;
    public const PASSWORD_MIN_LENGTH = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private TwoFactorChallengeRepository $challenges,
        private AppMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) ($_ENV['TWO_FACTOR_MODE'] ?? $_SERVER['TWO_FACTOR_MODE'] ?? 'disabled')));

        return \in_array($mode, ['disabled', 'optin', 'mandatory'], true) ? $mode : 'disabled';
    }

    public function isEnforcedFor(User $user): bool
    {
        if ($user->isTwoFactorExempt()) {
            return false;
        }
        $mode = $this->mode();
        if ('mandatory' === $mode) {
            return true;
        }
        if ('optin' === $mode) {
            return $user->getIsAdmin() || $user->isTwoFactorEnabled();
        }

        return false;
    }

    public function needsEnrollment(User $user): bool
    {
        return $this->isEnforcedFor($user) && !$user->hasVerifiedEmail();
    }

    /**
     * Days left to enroll before enforcement (null when not applicable).
     */
    public function graceDaysLeft(): ?int
    {
        $until = trim((string) ($_ENV['TWO_FACTOR_GRACE_UNTIL'] ?? $_SERVER['TWO_FACTOR_GRACE_UNTIL'] ?? ''));
        if ('' === $until) {
            return null;
        }
        try {
            $deadline = new \DateTimeImmutable($until . ' 23:59:59');
        } catch (\Throwable) {
            return null;
        }
        $diff = (new \DateTimeImmutable())->diff($deadline);

        return $diff->invert ? 0 : (int) $diff->days;
    }

    public function inGrace(): bool
    {
        $left = $this->graceDaysLeft();

        return null === $left || $left > 0;
    }

    public function maskedEmail(?string $email): string
    {
        if (null === $email || '' === trim($email) || !str_contains($email, '@')) {
            return '';
        }
        [$name, $domain] = explode('@', $email, 2);

        return substr($name, 0, 1) . '***@' . $domain;
    }

    /**
     * Issues a fresh challenge (invalidating previous active ones of the
     * same purpose) and mails the code. Returns [challenge, plaintextCode].
     *
     * @return array{0: TwoFactorChallenge|null, 1: string|null}
     */
    public function issue(User $user, string $purpose, ?string $email = null): array
    {
        $to = $email ?? $user->getEmail();
        if (null === $to || '' === trim($to)) {
            return [null, null];
        }

        $previous = $this->challenges->findActiveForUser((int) $user->getId(), $purpose);
        if ($previous) {
            $previous->consume();
        }

        $code = (string) random_int(10 ** (TwoFactorChallenge::CODE_LENGTH - 1), (10 ** TwoFactorChallenge::CODE_LENGTH) - 1);
        $challenge = (new TwoFactorChallenge())
            ->setUser($user)
            ->setPurpose($purpose)
            ->setCodeHash(hash('sha256', $code))
            ->setExpiresAt(new \DateTimeImmutable('+' . TwoFactorChallenge::TTL_SECONDS . ' seconds'))
            ->markSent();
        $this->em->persist($challenge);
        $this->em->flush();

        if (!$this->mailer->sendCode($to, $code, $purpose)) {
            $this->logger->warning('[2FA] mail failed, challenge pending resend', [
                'user' => $user->getCode(),
                'purpose' => $purpose,
            ]);
        }

        return [$challenge, $code];
    }

    public function verify(TwoFactorChallenge $challenge, string $code): bool
    {
        if (!$challenge->isUsable()) {
            return false;
        }
        $code = trim($code);
        if (!hash_equals($challenge->getCodeHash(), hash('sha256', $code))) {
            $challenge->hitAttempt();
            $this->em->flush();
            $this->logger->info('[2FA] wrong code', [
                'user' => $challenge->getUser()?->getCode(),
                'attempts' => $challenge->getAttempts(),
            ]);

            return false;
        }
        $challenge->consume();
        $this->em->flush();

        return true;
    }

    /**
     * Rotates the code and re-sends. Returns null when cooldown/resend cap
     * blocks it (caller returns 429 with retry_after).
     *
     * @return array{challenge: TwoFactorChallenge}|null
     */
    public function resend(TwoFactorChallenge $challenge, string $email): ?array
    {
        if (!$challenge->isUsable()) {
            return null;
        }
        $last = $challenge->getLastSentAt();
        if ($last && (time() - $last->getTimestamp()) < self::RESEND_COOLDOWN_SECONDS) {
            return null;
        }
        if ($challenge->getResendCount() >= self::MAX_RESENDS + 1) {
            return null;
        }
        $code = (string) random_int(10 ** (TwoFactorChallenge::CODE_LENGTH - 1), (10 ** TwoFactorChallenge::CODE_LENGTH) - 1);
        $challenge
            ->setCodeHash(hash('sha256', $code))
            ->setExpiresAt(new \DateTimeImmutable('+' . TwoFactorChallenge::TTL_SECONDS . ' seconds'))
            ->markSent();
        $this->em->flush();
        $this->mailer->sendCode($email, $code, $challenge->getPurpose());

        return ['challenge' => $challenge];
    }

    public function resendRetryAfter(TwoFactorChallenge $challenge): int
    {
        $last = $challenge->getLastSentAt();
        if (!$last) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN_SECONDS - (time() - $last->getTimestamp()));
    }
}
