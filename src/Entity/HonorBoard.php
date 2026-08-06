<?php

namespace App\Entity;

use App\Repository\HonorBoardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tablero de honor - logros destacados de los jugadores.
 * Se actualiza automáticamente cuando se cumplen condiciones.
 */
#[ORM\Entity(repositoryClass: HonorBoardRepository::class)]
#[ORM\Table(name: 'honor_board')]
class HonorBoard
{
    public const CATEGORY_MOST_WINS = 'most_wins';
    public const CATEGORY_HIGHEST_STREAK = 'highest_streak';
    public const CATEGORY_BEST_WINRATE = 'best_winrate';
    public const CATEGORY_BIGGEST_EARNER = 'biggest_earner';
    public const CATEGORY_MOST_ACTIVE = 'most_active';
    public const CATEGORY_RICHEST = 'richest';
    public const CATEGORY_TOURNAMENT_CHAMPION = 'tournament_champion';
    public const CATEGORY_CLAN_LEADER = 'clan_leader';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 30)]
    private string $category = self::CATEGORY_MOST_WINS;

    #[ORM\Column]
    private int $value = 0;

    #[ORM\Column(length: 50)]
    private string $period = 'all_time'; // all_time, monthly, weekly

    #[ORM\Column(length: 20)]
    private string $season = ''; // S1, S2, etc.

    #[ORM\Column(length: 10)]
    private ?string $rank = null; // 🥇, 🥈, 🥉

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = []; // datos extra

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $c): self { $this->category = $c; return $this; }
    public function getValue(): int { return $this->value; }
    public function setValue(int $v): self { $this->value = $v; return $this; }
    public function getPeriod(): string { return $this->period; }
    public function setPeriod(string $p): self { $this->period = $p; return $this; }
    public function getSeason(): string { return $this->season; }
    public function setSeason(string $s): self { $this->season = $s; return $this; }
    public function getRank(): ?string { return $this->rank; }
    public function setRank(?string $r): self { $this->rank = $r; return $this; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $m): self { $this->metadata = $m; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $u): self { $this->updatedAt = $u; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}