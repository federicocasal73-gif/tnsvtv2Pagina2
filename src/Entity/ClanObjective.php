<?php

namespace App\Entity;

use App\Repository\ClanObjectiveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objetivo grupal del clan.
 * Los miembros contribuyen para completar el objetivo y ganar recompensas.
 */
#[ORM\Entity(repositoryClass: ClanObjectiveRepository::class)]
#[ORM\Table(name: 'clan_objectives')]
class ClanObjective
{
    public const TYPE_WINS = 'wins'; // Ganar X partidas
    public const TYPE_SCORE = 'score'; // Alcanzar X puntos totales
    public const TYPE_ACTIVE_PLAYERS = 'active_players'; // X miembros activos hoy
    public const TYPE_STREAK = 'streak'; // Racha de X días
    public const TYPE_TOURNAMENT = 'tournament'; // Top 3 en torneo

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Clan::class, inversedBy: 'objectives')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clan $clan = null;

    #[ORM\Column(length: 100)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_WINS;

    #[ORM\Column]
    private int $target = 0;

    #[ORM\Column]
    private int $current = 0;

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $rewards = []; // {coins: 100, reputation: 50, items: [...]}

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClan(): ?Clan { return $this->clan; }
    public function setClan(?Clan $c): self { $this->clan = $c; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): self { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): self { $this->type = $t; return $this; }
    public function getTarget(): int { return $this->target; }
    public function setTarget(int $t): self { $this->target = $t; return $this; }
    public function getCurrent(): int { return $this->current; }
    public function setCurrent(int $c): self { $this->current = $c; return $this; }
    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $c): self { $this->completed = $c; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $e): self { $this->expiresAt = $e; return $this; }
    public function getRewards(): array { return $this->rewards; }
    public function setRewards(array $r): self { $this->rewards = $r; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getProgress(): float
    {
        if ($this->target <= 0) return 0;
        return min(($this->current / $this->target) * 100, 100);
    }

    public function isExpired(): bool
    {
        if (!$this->expiresAt) return false;
        return new \DateTimeImmutable() > $this->expiresAt;
    }

    public function addProgress(int $amount): void
    {
        if ($this->completed) return;
        
        $this->current = min($this->current + $amount, $this->target);
        
        if ($this->current >= $this->target && !$this->completed) {
            $this->completed = true;
            $this->completedAt = new \DateTimeImmutable();
        }
    }
}