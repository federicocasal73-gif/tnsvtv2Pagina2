<?php

namespace App\Entity;

use App\Repository\TournamentEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Participacion de un user en un torneo.
 * Captura starting_equity al join, calcula pnl_pct en vivo, asigna final_rank y payout al cierre.
 */
#[ORM\Entity(repositoryClass: TournamentEntryRepository::class)]
#[ORM\Table(name: 'tournament_entries')]
#[ORM\UniqueConstraint(name: 'uniq_te_tournament_user', columns: ['tournament_id', 'user_id'])]
#[ORM\Index(name: 'idx_te_tournament', columns: ['tournament_id'])]
#[ORM\Index(name: 'idx_te_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_te_status', columns: ['status'])]
#[ORM\Index(name: 'idx_te_pnl', columns: ['tournament_id', 'pnl_pct'])]
class TournamentEntry
{
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_FINISHED    = 'finished';
    public const STATUS_DISQUALIFIED = 'disqualified';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tournament::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tournament $tournament = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $startingEquity = '0.0000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $finalEquity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $pnlUsd = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $pnlPct = null;

    #[ORM\Column(nullable: true)]
    private ?int $finalRank = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $payoutAmount = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finalizedAt = null;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTournament(): ?Tournament { return $this->tournament; }
    public function setTournament(?Tournament $t): self { $this->tournament = $t; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getStartingEquity(): string { return $this->startingEquity; }
    public function setStartingEquity(string $e): self { $this->startingEquity = $e; return $this; }
    public function getFinalEquity(): ?string { return $this->finalEquity; }
    public function setFinalEquity(?string $e): self { $this->finalEquity = $e; return $this; }
    public function getPnlUsd(): ?string { return $this->pnlUsd; }
    public function setPnlUsd(?string $p): self { $this->pnlUsd = $p; return $this; }
    public function getPnlPct(): ?string { return $this->pnlPct; }
    public function setPnlPct(?string $p): self { $this->pnlPct = $p; return $this; }
    public function getFinalRank(): ?int { return $this->finalRank; }
    public function setFinalRank(?int $r): self { $this->finalRank = $r; return $this; }
    public function getPayoutAmount(): ?string { return $this->payoutAmount; }
    public function setPayoutAmount(?string $p): self { $this->payoutAmount = $p; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getJoinedAt(): ?\DateTimeImmutable { return $this->joinedAt; }
    public function setJoinedAt(\DateTimeImmutable $d): self { $this->joinedAt = $d; return $this; }
    public function getFinalizedAt(): ?\DateTimeImmutable { return $this->finalizedAt; }
    public function setFinalizedAt(?\DateTimeImmutable $d): self { $this->finalizedAt = $d; return $this; }

    /**
     * Calcula el PnL pct en vivo desde el current_equity (Portfolio del user)
     * Retorna null si no hay starting_equity valido
     */
    public function computeCurrentPnl(string $currentEquity): ?array
    {
        $start = (float) $this->startingEquity;
        if ($start <= 0) return null;
        $cur = (float) $currentEquity;
        $pnlUsd = $cur - $start;
        $pnlPct = ($pnlUsd / $start) * 100;
        return [
            'pnl_usd' => number_format($pnlUsd, 4, '.', ''),
            'pnl_pct' => number_format($pnlPct, 6, '.', ''),
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
