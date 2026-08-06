<?php

namespace App\Entity;

use App\Repository\TournamentBracketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Torneo bracket de 48h con matches en vivo.
 * Formato: Single elimination, best of 3 rounds.
 * Cada match: 3 rondas de un modo específico.
 */
#[ORM\Entity(repositoryClass: TournamentBracketRepository::class)]
#[ORM\Table(name: 'tournament_brackets')]
class TournamentBracket
{
    public const STATUS_REGISTRATION = 'registration'; // Aceptando jugadores
    public const STATUS_ACTIVE = 'active';              // Matches en curso
    public const STATUS_FINISHED = 'finished';          // Torneo completo

    public const MODE_CLASSIC = 'classic';
    public const MODE_SURVIVAL = 'survival';
    public const MODE_PORTFOLIO = 'portfolio';
    public const MODE_RANDOM = 'random';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(length: 20)]
    private string $mode = self::MODE_CLASSIC;

    #[ORM\Column]
    private int $maxPlayers = 8; // Potencia de 2: 4, 8, 16, 32

    #[ORM\Column]
    private int $currentRound = 0;

    #[ORM\Column]
    private int $totalRounds = 0; // log2(maxPlayers)

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $entryFee = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $prizePool = '0.00';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_REGISTRATION;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column]
    private int $matchDurationMinutes = 480; // 8 horas por match

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: BracketMatch::class, cascade: ['remove'])]
    private Collection $matches;

    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: TournamentBracketEntry::class, cascade: ['remove'])]
    private Collection $entries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->matches = new ArrayCollection();
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $m): self { $this->mode = $m; return $this; }
    public function getMaxPlayers(): int { return $this->maxPlayers; }
    public function setMaxPlayers(int $m): self { $this->maxPlayers = $m; $this->totalRounds = (int) log($m, 2); return $this; }
    public function getCurrentRound(): int { return $this->currentRound; }
    public function setCurrentRound(int $r): self { $this->currentRound = $r; return $this; }
    public function getTotalRounds(): int { return $this->totalRounds; }
    public function getEntryFee(): string { return $this->entryFee; }
    public function setEntryFee(string $f): self { $this->entryFee = $f; return $this; }
    public function getPrizePool(): string { return $this->prizePool; }
    public function setPrizePool(string $p): self { $this->prizePool = $p; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $d): self { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $d): self { $this->endDate = $d; return $this; }
    public function getMatchDurationMinutes(): int { return $this->matchDurationMinutes; }
    public function setMatchDurationMinutes(int $m): self { $this->matchDurationMinutes = $m; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getMatches(): Collection { return $this->matches; }
    public function getEntries(): Collection { return $this->entries; }

    public function getRoundName(int $round): string
    {
        $total = $this->totalRounds;
        if ($round === $total) return 'FINAL';
        if ($round === $total - 1) return 'SEMIFINAL';
        if ($round === $total - 2) return 'CUARTOS';
        return "Ronda $round";
    }

    public function getMatchesInRound(int $round): int
    {
        return $this->maxPlayers / pow(2, $round);
    }
}