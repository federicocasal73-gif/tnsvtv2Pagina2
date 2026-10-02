<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TwoFactorChallengeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One-time email code challenge for 2FA login, email enrollment and
 * password recovery.
 *
 * Security rules (enforced by TwoFactorService, not the DB):
 * - only the sha256 of the code is stored, never the code itself;
 * - 2 minute TTL, single use, max 5 attempts, resend cooldown 60s.
 */
#[ORM\Entity(repositoryClass: TwoFactorChallengeRepository::class)]
#[ORM\Table(name: 'two_factor_challenges')]
#[ORM\Index(columns: ['user_id', 'purpose', 'consumed_at'], name: 'idx_tfc_user_purpose')]
class TwoFactorChallenge
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_ENROLL = 'enroll';
    public const PURPOSE_RESET = 'reset';

    public const TTL_SECONDS = 120;
    public const MAX_ATTEMPTS = 5;
    public const CODE_LENGTH = 6;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 16)]
    private string $purpose = self::PURPOSE_LOGIN;

    /** sha256 hex of the 6-digit code. */
    #[ORM\Column(length: 64)]
    private string $codeHash = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $resendCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getPurpose(): string { return $this->purpose; }
    public function setPurpose(string $purpose): static { $this->purpose = $purpose; return $this; }

    public function getCodeHash(): string { return $this->codeHash; }
    public function setCodeHash(string $codeHash): static { $this->codeHash = $codeHash; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function getAttempts(): int { return $this->attempts; }
    public function hitAttempt(): static { $this->attempts++; return $this; }

    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function consume(): static { $this->consumedAt = new \DateTimeImmutable(); return $this; }

    public function getLastSentAt(): ?\DateTimeImmutable { return $this->lastSentAt; }
    public function markSent(): static
    {
        $this->lastSentAt = new \DateTimeImmutable();
        $this->resendCount++;
        return $this;
    }

    public function getResendCount(): int { return $this->resendCount; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function isExpired(): bool
    {
        return null === $this->expiresAt || $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isUsable(): bool
    {
        return null === $this->consumedAt && !$this->isExpired() && $this->attempts < self::MAX_ATTEMPTS;
    }
}
