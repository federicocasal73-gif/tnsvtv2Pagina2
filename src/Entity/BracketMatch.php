<?php

namespace App\Entity;

use App\Repository\BracketMatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un match individual dentro del bracket.
 * Best of 3: primer jugador en ganar 2 rondas avanza.
 */
#[ORM\Entity(repositoryClass: BracketMatchRepository::class)]
#[ORM\Table(name: 'bracket_matches')]
#[ORM\Index(columns: ['tournament_id', 'round'], name: 'idx_bm_tournament_round')]
class BracketMatch
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_BYE = 'bye'; // Jugador avanza por bye

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TournamentBracket::class, inversedBy: 'matches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TournamentBracket $tournament = null;

    #[ORM\Column]
    private int $round = 0;

    #[ORM\Column]
    private int $matchIndex = 0; // Posición en el bracket (0-3 para cuartos, etc.)

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $player1 = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $player2 = null;

    #[ORM\Column(nullable: true)]
    private ?int $player1Score = null; // Rondas ganadas

    #[ORM\Column(nullable: true)]
    private ?int $player2Score = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $winner = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roundResults = []; // [{player1: 1500, player2: 1200, winner: 1}, ...]

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTournament(): ?TournamentBracket { return $this->tournament; }
    public function setTournament(?TournamentBracket $t): self { $this->tournament = $t; return $this; }
    public function getRound(): int { return $this->round; }
    public function setRound(int $r): self { $this->round = $r; return $this; }
    public function getMatchIndex(): int { return $this->matchIndex; }
    public function setMatchIndex(int $i): self { $this->matchIndex = $i; return $this; }
    public function getPlayer1(): ?User { return $this->player1; }
    public function setPlayer1(?User $u): self { $this->player1 = $u; return $this; }
    public function getPlayer2(): ?User { return $this->player2; }
    public function setPlayer2(?User $u): self { $this->player2 = $u; return $this; }
    public function getPlayer1Score(): ?int { return $this->player1Score; }
    public function setPlayer1Score(?int $s): self { $this->player1Score = $s; return $this; }
    public function getPlayer2Score(): ?int { return $this->player2Score; }
    public function setPlayer2Score(?int $s): self { $this->player2Score = $s; return $this; }
    public function getWinner(): ?User { return $this->winner; }
    public function setWinner(?User $w): self { $this->winner = $w; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $d): self { $this->startedAt = $d; return $this; }
    public function getDeadline(): ?\DateTimeImmutable { return $this->deadline; }
    public function setDeadline(?\DateTimeImmutable $d): self { $this->deadline = $d; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $d): self { $this->finishedAt = $d; return $this; }
    public function getRoundResults(): array { return $this->roundResults; }
    public function setRoundResults(array $r): self { $this->roundResults = $r; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function recordRound(int $player1Score, int $player2Score): void
    {
        $this->roundResults[] = [
            'player1' => $player1Score,
            'player2' => $player2Score,
            'winner' => $player1Score > $player2Score ? 1 : ($player2Score > $player1Score ? 2 : 0),
        ];

        // Update series score
        $p1Wins = 0;
        $p2Wins = 0;
        foreach ($this->roundResults as $r) {
            if ($r['winner'] === 1) $p1Wins++;
            elseif ($r['winner'] === 2) $p2Wins++;
        }
        $this->player1Score = $p1Wins;
        $this->player2Score = $p2Wins;

        // Check if match is over (best of 3)
        if ($p1Wins >= 2 || $p2Wins >= 2) {
            $this->winner = $p1Wins >= 2 ? $this->player1 : $this->player2;
            $this->status = self::STATUS_FINISHED;
            $this->setFinishedAt(new \DateTimeImmutable());
        }
    }
}