<?php

namespace App\Entity;

use App\Repository\DuelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DuelRepository::class)]
#[ORM\Table(name: 'duels')]
#[ORM\Index(name: 'idx_duel_code', columns: ['code'])]
#[ORM\Index(name: 'idx_duel_status', columns: ['status'])]
class Duel
{
    public const STATUS_WAITING  = 'waiting';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $code = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $player1 = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $player2 = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $winner = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $entryFee = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $prizePool = '0.00';

    #[ORM\Column]
    private int $totalRounds = 5;

    #[ORM\Column]
    private int $currentRound = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $player1Pnl = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $player2Pnl = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $startingPrice = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_WAITING;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\OneToMany(mappedBy: 'duel', targetEntity: DuelRound::class, orphanRemoval: true)]
    #[ORM\OrderBy(['roundNumber' => 'ASC'])]
    private Collection $rounds;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->rounds = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getPlayer1(): ?User { return $this->player1; }
    public function setPlayer1(?User $p): self { $this->player1 = $p; return $this; }
    public function getPlayer2(): ?User { return $this->player2; }
    public function setPlayer2(?User $p): self { $this->player2 = $p; return $this; }
    public function getWinner(): ?User { return $this->winner; }
    public function setWinner(?User $w): self { $this->winner = $w; return $this; }
    public function getEntryFee(): string { return $this->entryFee; }
    public function setEntryFee(string $f): self { $this->entryFee = $f; return $this; }
    public function getPrizePool(): string { return $this->prizePool; }
    public function setPrizePool(string $p): self { $this->prizePool = $p; return $this; }
    public function getTotalRounds(): int { return $this->totalRounds; }
    public function setTotalRounds(int $r): self { $this->totalRounds = $r; return $this; }
    public function getCurrentRound(): int { return $this->currentRound; }
    public function setCurrentRound(int $r): self { $this->currentRound = $r; return $this; }
    public function getPlayer1Pnl(): string { return $this->player1Pnl; }
    public function setPlayer1Pnl(string $p): self { $this->player1Pnl = $p; return $this; }
    public function getPlayer2Pnl(): string { return $this->player2Pnl; }
    public function setPlayer2Pnl(string $p): self { $this->player2Pnl = $p; return $this; }
    public function getStartingPrice(): ?string { return $this->startingPrice; }
    public function setStartingPrice(?string $p): self { $this->startingPrice = $p; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $d): self { $this->startedAt = $d; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $d): self { $this->finishedAt = $d; return $this; }
    public function getRounds(): Collection { return $this->rounds; }

    public function isWaiting(): bool { return $this->status === self::STATUS_WAITING; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isFinished(): bool { return $this->status === self::STATUS_FINISHED; }

    public function hasPlayer(User $user): bool
    {
        return ($this->player1 && $this->player1->getId() === $user->getId())
            || ($this->player2 && $this->player2->getId() === $user->getId());
    }
}
