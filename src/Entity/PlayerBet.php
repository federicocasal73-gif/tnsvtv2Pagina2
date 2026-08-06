<?php

namespace App\Entity;

use App\Repository\PlayerBetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Apuesta P2P entre dos jugadores.
 * Un jugador desafía a otro con una cantidad de coins.
 * El ganador se lleva el pozo total.
 */
#[ORM\Entity(repositoryClass: PlayerBetRepository::class)]
#[ORM\Table(name: 'player_bets')]
class PlayerBet
{
    public const STATUS_PENDING = 'pending';     // Esperando que el oponente acepte
    public const STATUS_ACCEPTED = 'accepted';   // Aceptada, jugando
    public const STATUS_COMPLETED = 'completed'; // Finalizada, pagada
    public const STATUS_CANCELLED = 'cancelled'; // Cancelada
    public const STATUS_EXPIRED = 'expired';     // No fue aceptada a tiempo

    public const MODE_CLASSIC = 'classic';
    public const MODE_SURVIVAL = 'survival';
    public const MODE_PORTFOLIO = 'portfolio';
    public const MODE_RANDOM = 'random';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $challenger = null; // Quien desafía

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $opponent = null; // Quien es desafiado (null si es challenge abierto)

    #[ORM\Column]
    private int $amount = 0; // Cantidad de coins apostados por cada uno

    #[ORM\Column]
    private int $totalPot = 0; // Total = amount * 2

    #[ORM\Column(length: 20)]
    private string $mode = self::MODE_CLASSIC;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    private ?int $challengerScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $opponentScore = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $winner = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = []; // gameData, roundHistory, etc.

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getChallenger(): ?User { return $this->challenger; }
    public function setChallenger(?User $u): self { $this->challenger = $u; return $this; }
    public function getOpponent(): ?User { return $this->opponent; }
    public function setOpponent(?User $u): self { $this->opponent = $u; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $a): self { $this->amount = $a; $this->totalPot = $a * 2; return $this; }
    public function getTotalPot(): int { return $this->totalPot; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $m): self { $this->mode = $m; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getChallengerScore(): ?int { return $this->challengerScore; }
    public function setChallengerScore(?int $s): self { $this->challengerScore = $s; return $this; }
    public function getOpponentScore(): ?int { return $this->opponentScore; }
    public function setOpponentScore(?int $s): self { $this->opponentScore = $s; return $this; }
    public function getWinner(): ?User { return $this->winner; }
    public function setWinner(?User $w): self { $this->winner = $w; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $e): self { $this->expiresAt = $e; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $c): self { $this->completedAt = $c; return $this; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $m): self { $this->metadata = $m; return $this; }

    public function isExpired(): bool
    {
        if (!$this->expiresAt) return false;
        return new \DateTimeImmutable() > $this->expiresAt;
    }

    public function resolve(User $winnerUser, int $challengerScore, int $opponentScore): void
    {
        $this->winner = $winnerUser;
        $this->challengerScore = $challengerScore;
        $this->opponentScore = $opponentScore;
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
    }
}