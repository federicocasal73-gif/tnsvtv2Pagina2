<?php

namespace App\Entity;

use App\Repository\TournamentBracketEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Participación de un jugador en un torneo bracket.
 */
#[ORM\Entity(repositoryClass: TournamentBracketEntryRepository::class)]
#[ORM\Table(name: 'tournament_bracket_entries')]
#[ORM\UniqueConstraint(name: 'uniq_tbe_tournament_user', columns: ['tournament_id', 'user_id'])]
class TournamentBracketEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TournamentBracket::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TournamentBracket $tournament = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $finalRank = null; // Posición final (1 = ganador)

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $prizeWon = null;

    #[ORM\Column]
    private bool $eliminated = false;

    #[ORM\Column(nullable: true)]
    private ?int $eliminatedRound = null;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTournament(): ?TournamentBracket { return $this->tournament; }
    public function setTournament(?TournamentBracket $t): self { $this->tournament = $t; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getJoinedAt(): ?\DateTimeImmutable { return $this->joinedAt; }
    public function getFinalRank(): ?int { return $this->finalRank; }
    public function setFinalRank(?int $r): self { $this->finalRank = $r; return $this; }
    public function getPrizeWon(): ?string { return $this->prizeWon; }
    public function setPrizeWon(?string $p): self { $this->prizeWon = $p; return $this; }
    public function isEliminated(): bool { return $this->eliminated; }
    public function setEliminated(bool $e): self { $this->eliminated = $e; return $this; }
    public function getEliminatedRound(): ?int { return $this->eliminatedRound; }
    public function setEliminatedRound(?int $r): self { $this->eliminatedRound = $r; return $this; }
}