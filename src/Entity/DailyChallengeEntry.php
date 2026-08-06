<?php

namespace App\Entity;

use App\Repository\DailyChallengeEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Intento de un usuario en un desafío diario.
 */
#[ORM\Entity(repositoryClass: DailyChallengeEntryRepository::class)]
#[ORM\Table(name: 'daily_challenge_entries')]
#[ORM\UniqueConstraint(name: 'uniq_dce_challenge_user', columns: ['challenge_id', 'user_id'])]
class DailyChallengeEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DailyChallenge::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DailyChallenge $challenge = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(nullable: true)]
    private ?int $timeSpent = null; // segundos

    #[ORM\Column(nullable: true)]
    private ?int $accuracy = null; // porcentaje 0-100

    #[ORM\Column]
    private ?int $rank = null; // posición en el leaderboard del día

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = []; // datos del intento

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getChallenge(): ?DailyChallenge { return $this->challenge; }
    public function setChallenge(?DailyChallenge $c): self { $this->challenge = $c; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getScore(): int { return $this->score; }
    public function setScore(int $s): self { $this->score = $s; return $this; }
    public function getTimeSpent(): ?int { return $this->timeSpent; }
    public function setTimeSpent(?int $t): self { $this->timeSpent = $t; return $this; }
    public function getAccuracy(): ?int { return $this->accuracy; }
    public function setAccuracy(?int $a): self { $this->accuracy = $a; return $this; }
    public function getRank(): ?int { return $this->rank; }
    public function setRank(?int $r): self { $this->rank = $r; return $this; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $m): self { $this->metadata = $m; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}