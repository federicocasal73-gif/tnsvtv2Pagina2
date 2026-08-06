<?php

namespace App\Entity;

use App\Repository\GameScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameScoreRepository::class)]
#[ORM\Table(name: 'game_scores')]
#[ORM\Index(columns: ['user_id', 'mode'], name: 'idx_game_user_mode')]
#[ORM\Index(columns: ['score'], name: 'idx_game_score')]
#[ORM\Index(columns: ['created_at'], name: 'idx_game_created')]
class GameScore
{
    public const MODE_CLASSIC  = 'classic';
    public const MODE_SURVIVAL = 'survival';
    public const MODE_DAILY    = 'daily';
    public const MODE_ARENA    = 'arena';
    public const MODE_TORNEO   = 'torneo';
    public const MODE_FRACTAL  = 'fractal';
    public const MODE_PORTFOLIO= 'portfolio';
    public const MODE_HIST     = 'hist';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    private ?string $mode = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $xpGained = 0;

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getMode(): ?string { return $this->mode; }
    public function setMode(string $mode): static { $this->mode = $mode; return $this; }
    public function getScore(): int { return $this->score; }
    public function setScore(int $score): static { $this->score = $score; return $this; }
    public function getXpGained(): int { return $this->xpGained; }
    public function setXpGained(int $xpGained): static { $this->xpGained = $xpGained; return $this; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $metadata): static { $this->metadata = $metadata; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
