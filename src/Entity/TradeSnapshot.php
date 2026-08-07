<?php

namespace App\Entity;

use App\Repository\TradeSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * TradeSnapshot — per-user per-day aggregated metrics for the Oráculo.
 * Populated by a scheduled command (or computed on-read from journal_entries).
 */
#[ORM\Entity(repositoryClass: TradeSnapshotRepository::class)]
#[ORM\Table(name: 'trade_snapshots')]
#[ORM\UniqueConstraint(name: 'uniq_user_date', columns: ['user_code', 'snapshot_date'])]
#[ORM\Index(name: 'idx_snap_date', columns: ['snapshot_date'])]
class TradeSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $userCode = '';

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $snapshotDate = null;

    #[ORM\Column]
    private int $tradesTotal = 0;

    #[ORM\Column]
    private int $tradesWin = 0;

    #[ORM\Column]
    private int $tradesLoss = 0;

    #[ORM\Column]
    private int $tradesBe = 0;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4, nullable: true)]
    private ?string $pnlTotal = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4, nullable: true)]
    private ?string $pnlAvg = null;

    #[ORM\Column(type: 'json')]
    private array $assets = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUserCode(): string { return $this->userCode; }
    public function setUserCode(string $c): static { $this->userCode = $c; return $this; }
    public function getSnapshotDate(): ?\DateTimeImmutable { return $this->snapshotDate; }
    public function setSnapshotDate(\DateTimeImmutable $d): static { $this->snapshotDate = $d; return $this; }
    public function getTradesTotal(): int { return $this->tradesTotal; }
    public function setTradesTotal(int $v): static { $this->tradesTotal = $v; return $this; }
    public function getTradesWin(): int { return $this->tradesWin; }
    public function setTradesWin(int $v): static { $this->tradesWin = $v; return $this; }
    public function getTradesLoss(): int { return $this->tradesLoss; }
    public function setTradesLoss(int $v): static { $this->tradesLoss = $v; return $this; }
    public function getTradesBe(): int { return $this->tradesBe; }
    public function setTradesBe(int $v): static { $this->tradesBe = $v; return $this; }
    public function getPnlTotal(): ?string { return $this->pnlTotal; }
    public function setPnlTotal(?string $v): static { $this->pnlTotal = $v; return $this; }
    public function getPnlAvg(): ?string { return $this->pnlAvg; }
    public function setPnlAvg(?string $v): static { $this->pnlAvg = $v; return $this; }
    public function getAssets(): array { return $this->assets; }
    public function setAssets(array $a): static { $this->assets = $a; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }
}