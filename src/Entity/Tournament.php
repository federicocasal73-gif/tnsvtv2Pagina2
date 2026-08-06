<?php

namespace App\Entity;

use App\Repository\TournamentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Competicion entre users donde cada uno paga un entry_fee
 * y el que mas PnL genera en su Portfolio gana el prize_pool.
 *
 * Duracion tipica: 1 semana. Ranking: por pnl_pct descendente.
 * Distribucion: configurable (default 60/30/10 para top 1/2/3).
 */
#[ORM\Entity(repositoryClass: TournamentRepository::class)]
#[ORM\Table(name: 'tournaments')]
#[ORM\Index(name: 'idx_trn_status', columns: ['status'])]
#[ORM\Index(name: 'idx_trn_end_date', columns: ['end_date'])]
#[ORM\Index(name: 'idx_trn_active', columns: ['status', 'end_date'])]
class Tournament
{
    public const STATUS_PENDING  = 'pending';   // creado pero no arranco
    public const STATUS_ACTIVE   = 'active';    // aceptando entries / corriendo
    public const STATUS_CLOSED   = 'closed';    // tiempo cumplido, calculando winners
    public const STATUS_FINISHED = 'finished';  // payouts distribuidos
    public const STATUS_CANCELLED = 'cancelled'; // cancelado (pocos participants, etc)

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $entryFee = '5.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $prizePool = '0.00';

    #[ORM\Column(length: 20)]
    private string $prizeDistribution = '60,30,10';

    #[ORM\Column]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $maxPlayers = 100;

    #[ORM\Column]
    private int $minPlayers = 2;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: TournamentEntry::class, cascade: ['remove'])]
    private Collection $entries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getEntryFee(): string { return $this->entryFee; }
    public function setEntryFee(string $f): self { $this->entryFee = $f; return $this; }
    public function getPrizePool(): string { return $this->prizePool; }
    public function setPrizePool(string $p): self { $this->prizePool = $p; return $this; }
    public function getPrizeDistribution(): string { return $this->prizeDistribution; }
    public function setPrizeDistribution(string $d): self { $this->prizeDistribution = $d; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $d): self { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(\DateTimeImmutable $d): self { $this->endDate = $d; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getMaxPlayers(): int { return $this->maxPlayers; }
    public function setMaxPlayers(int $m): self { $this->maxPlayers = $m; return $this; }
    public function getMinPlayers(): int { return $this->minPlayers; }
    public function setMinPlayers(int $m): self { $this->minPlayers = $m; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): self { $this->createdAt = $d; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $d): self { $this->finishedAt = $d; return $this; }
    public function getEntries(): Collection { return $this->entries; }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isFinished(): bool { return $this->status === self::STATUS_FINISHED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
    public function isClosed(): bool { return $this->status === self::STATUS_CLOSED; }

    public function getDurationDays(): int
    {
        if (!$this->startDate || !$this->endDate) return 0;
        return (int) $this->endDate->diff($this->startDate)->days;
    }

    public function getDaysRemaining(): int
    {
        if (!$this->endDate) return 0;
        $now = new \DateTimeImmutable();
        if ($now > $this->endDate) return 0;
        return (int) $this->endDate->diff($now)->days;
    }

    public function getCurrentPrizePool(): string
    {
        $base = (float) $this->prizePool;
        $additional = $this->entries->count() * (float) $this->entryFee;
        return number_format($base + $additional, 2, '.', '');
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING  => 'Pendiente',
            self::STATUS_ACTIVE   => 'Activo',
            self::STATUS_CLOSED   => 'Cerrado',
            self::STATUS_FINISHED => 'Finalizado',
            self::STATUS_CANCELLED => 'Cancelado',
            default => $this->status,
        };
    }

    public function getDistributionPcts(): array
    {
        $parts = explode(',', $this->prizeDistribution);
        return array_map('intval', $parts);
    }
}
