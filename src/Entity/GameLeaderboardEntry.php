<?php

namespace App\Entity;

use App\Repository\GameLeaderboardEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameLeaderboardEntryRepository::class)]
#[ORM\Table(name: 'game_leaderboard_entries')]
#[ORM\Index(columns: ['user_id', 'leaderboard_type', 'period'], name: 'idx_lb_user_type_period')]
#[ORM\Index(columns: ['score', 'leaderboard_type', 'period'], name: 'idx_lb_score_type_period')]
class GameLeaderboardEntry
{
    public const TYPE_COINS = 'coins';
    public const TYPE_REPUTATION = 'reputation';
    public const TYPE_SEASON_XP = 'season_xp';
    public const TYPE_RANK_POINTS = 'rank_points';

    public const PERIOD_ALL_TIME = 'all_time';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private ?string $leaderboardType = null;

    #[ORM\Column(length: 20)]
    private ?string $period = null;

    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $seasonId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getLeaderboardType(): ?string { return $this->leaderboardType; }
    public function setLeaderboardType(string $leaderboardType): static { $this->leaderboardType = $leaderboardType; return $this; }

    public function getPeriod(): ?string { return $this->period; }
    public function setPeriod(string $period): static { $this->period = $period; return $this; }

    public function getScore(): int { return $this->score; }
    public function setScore(int $score): static { $this->score = $score; return $this; }

    public function getSeasonId(): ?string { return $this->seasonId; }
    public function setSeasonId(?string $seasonId): static { $this->seasonId = $seasonId; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}