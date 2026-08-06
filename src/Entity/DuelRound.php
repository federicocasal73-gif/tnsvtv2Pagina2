<?php

namespace App\Entity;

use App\Repository\DuelRoundRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DuelRoundRepository::class)]
#[ORM\Table(name: 'duel_rounds')]
#[ORM\Index(name: 'idx_dr_duel', columns: ['duel_id'])]
class DuelRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Duel::class, inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Duel $duel = null;

    #[ORM\Column]
    private int $roundNumber = 0;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $player1Move = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $player2Move = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $openPrice = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $closePrice = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $highPrice = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $lowPrice = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $player1Pnl = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $player2Pnl = '0.0000';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDuel(): ?Duel { return $this->duel; }
    public function setDuel(?Duel $d): self { $this->duel = $d; return $this; }
    public function getRoundNumber(): int { return $this->roundNumber; }
    public function setRoundNumber(int $r): self { $this->roundNumber = $r; return $this; }
    public function getPlayer1Move(): ?string { return $this->player1Move; }
    public function setPlayer1Move(?string $m): self { $this->player1Move = $m; return $this; }
    public function getPlayer2Move(): ?string { return $this->player2Move; }
    public function setPlayer2Move(?string $m): self { $this->player2Move = $m; return $this; }
    public function getOpenPrice(): string { return $this->openPrice; }
    public function setOpenPrice(string $p): self { $this->openPrice = $p; return $this; }
    public function getClosePrice(): string { return $this->closePrice; }
    public function setClosePrice(string $p): self { $this->closePrice = $p; return $this; }
    public function getHighPrice(): string { return $this->highPrice; }
    public function setHighPrice(string $p): self { $this->highPrice = $p; return $this; }
    public function getLowPrice(): string { return $this->lowPrice; }
    public function setLowPrice(string $p): self { $this->lowPrice = $p; return $this; }
    public function getPlayer1Pnl(): string { return $this->player1Pnl; }
    public function setPlayer1Pnl(string $p): self { $this->player1Pnl = $p; return $this; }
    public function getPlayer2Pnl(): string { return $this->player2Pnl; }
    public function setPlayer2Pnl(string $p): self { $this->player2Pnl = $p; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function isBothPlayed(): bool
    {
        return $this->player1Move !== null && $this->player2Move !== null;
    }

    public function getPlayer1Direction(): float
    {
        if ($this->player1Move === 'long') return 1;
        if ($this->player1Move === 'short') return -1;
        return 0;
    }

    public function getPlayer2Direction(): float
    {
        if ($this->player2Move === 'long') return 1;
        if ($this->player2Move === 'short') return -1;
        return 0;
    }

    public function computePnl(): void
    {
        $open = (float) $this->openPrice;
        $close = (float) $this->closePrice;
        if ($open <= 0) return;
        $returnPct = (($close - $open) / $open) * 100;
        $this->player1Pnl = number_format($returnPct * $this->getPlayer1Direction(), 4, '.', '');
        $this->player2Pnl = number_format($returnPct * $this->getPlayer2Direction(), 4, '.', '');
    }
}
