<?php

namespace App\Entity;

use App\Repository\TradeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trade ejecutado durante un torneo, con outcome server-authoritative.
 *
 * El cliente envia solo {symbol, direction, timeframe}.
 * El server toma entry_price (snapshot propio), genera exit_price (snapshot
 * o candle deterministico), computa pnl server-side y actualiza la equity
 * del TournamentEntry. El cliente nunca postea su propio resultado.
 *
 * Cada fila aqui queda como auditoria/antifraude (no se borra al cerrar
 * el torneo).
 */
#[ORM\Entity(repositoryClass: TradeRepository::class)]
#[ORM\Table(name: 'tournament_trades')]
#[ORM\Index(name: 'idx_tr_entry', columns: ['entry_id'])]
#[ORM\Index(name: 'idx_tr_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_tr_tournament', columns: ['tournament_id'])]
#[ORM\Index(name: 'idx_tr_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_tr_status', columns: ['status'])]
class Trade
{
    public const DIRECTION_LONG  = 'long';
    public const DIRECTION_SHORT = 'short';
    public const DIRECTION_SKIP  = 'skip';

    public const STATUS_PENDING  = 'pending';   // registrado, exit_price aun no resuelto
    public const STATUS_RESOLVED = 'resolved'; // exit_price computado, equity actualizada
    public const STATUS_REJECTED = 'rejected'; // invalido (symbol fuera de whitelist, etc)

    public const TIMEFRAMES = ['1M','5M','15M','1H','4H','1D'];

    public const SYMBOL_WHITELIST = ['BTC','ETH','SOL','BNB','XRP','GOLD','SP500','EURUSD'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TournamentEntry::class, inversedBy: 'trades')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TournamentEntry $entry = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tournament::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tournament $tournament = null;

    #[ORM\Column(length: 16)]
    private string $symbol = '';

    #[ORM\Column(length: 8)]
    private string $direction = self::DIRECTION_SKIP;

    #[ORM\Column(length: 4)]
    private string $timeframe = '5M';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $entryPrice = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $exitPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $pnlUsd = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $pnlPct = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $sizePct = '100.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $leverage = '1.00';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 16)]
    private string $priceSource = 'snapshot';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntry(): ?TournamentEntry { return $this->entry; }
    public function setEntry(?TournamentEntry $e): self { $this->entry = $e; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getTournament(): ?Tournament { return $this->tournament; }
    public function setTournament(?Tournament $t): self { $this->tournament = $t; return $this; }
    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $s): self { $this->symbol = $s; return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $d): self { $this->direction = $d; return $this; }
    public function getTimeframe(): string { return $this->timeframe; }
    public function setTimeframe(string $t): self { $this->timeframe = $t; return $this; }
    public function getEntryPrice(): string { return $this->entryPrice; }
    public function setEntryPrice(string $p): self { $this->entryPrice = $p; return $this; }
    public function getExitPrice(): ?string { return $this->exitPrice; }
    public function setExitPrice(?string $p): self { $this->exitPrice = $p; return $this; }
    public function getPnlUsd(): ?string { return $this->pnlUsd; }
    public function setPnlUsd(?string $p): self { $this->pnlUsd = $p; return $this; }
    public function getPnlPct(): ?string { return $this->pnlPct; }
    public function setPnlPct(?string $p): self { $this->pnlPct = $p; return $this; }
    public function getSizePct(): string { return $this->sizePct; }
    public function setSizePct(string $p): self { $this->sizePct = $p; return $this; }
    public function getLeverage(): string { return $this->leverage; }
    public function setLeverage(string $l): self { $this->leverage = $l; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getPriceSource(): string { return $this->priceSource; }
    public function setPriceSource(string $s): self { $this->priceSource = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): self { $this->createdAt = $d; return $this; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $d): self { $this->resolvedAt = $d; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function isResolved(): bool { return $this->status === self::STATUS_RESOLVED; }

    /**
     * Computa el PnL del trade.
     * direction long  -> pnl = (exit - entry) / entry
     * direction short -> pnl = (entry - exit) / entry
     * direction skip  -> pnl = 0
     * Multiplicado por leverage y size_pct (fraccion de la equity arriesgada).
     *
     * @param float $equityAtEntry  equity del entry al momento de ejecutar el trade
     */
    public function computePnl(float $equityAtEntry): array
    {
        $entry = (float) $this->entryPrice;
        $exit = (float) ($this->exitPrice ?? $entry);
        $lev = max(0.01, (float) $this->leverage);
        $size = max(0.01, min(100, (float) $this->sizePct)) / 100;

        if ($entry <= 0) {
            return ['pnl_pct' => '0.000000', 'pnl_usd' => '0.0000'];
        }

        $rawPct = match ($this->direction) {
            self::DIRECTION_LONG  => ($exit - $entry) / $entry,
            self::DIRECTION_SHORT => ($entry - $exit) / $entry,
            default => 0.0,
        };

        $pnlPct = $rawPct * $lev * 100;
        $pnlUsd = $equityAtEntry * $size * ($rawPct * $lev);

        return [
            'pnl_pct' => number_format($pnlPct, 6, '.', ''),
            'pnl_usd' => number_format($pnlUsd, 4, '.', ''),
        ];
    }
}