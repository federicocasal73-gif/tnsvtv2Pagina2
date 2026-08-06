<?php

namespace App\Entity;

use App\Repository\ClanMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Miembro de un clan con rol y estadísticas.
 */
#[ORM\Entity(repositoryClass: ClanMemberRepository::class)]
#[ORM\Table(name: 'clan_members')]
#[ORM\UniqueConstraint(name: 'uniq_cm_clan_user', columns: ['clan_id', 'user_id'])]
class ClanMember
{
    public const ROLE_LEADER = 'leader';
    public const ROLE_OFFICER = 'officer';
    public const ROLE_MEMBER = 'member';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Clan::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clan $clan = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private string $role = self::ROLE_MEMBER;

    #[ORM\Column]
    private int $contribution = 0; // Puntos de contribución al clan

    #[ORM\Column]
    private int $weeklyContribution = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActiveAt = null;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClan(): ?Clan { return $this->clan; }
    public function setClan(?Clan $c): self { $this->clan = $c; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $r): self { $this->role = $r; return $this; }
    public function getContribution(): int { return $this->contribution; }
    public function setContribution(int $c): self { $this->contribution = $c; return $this; }
    public function getWeeklyContribution(): int { return $this->weeklyContribution; }
    public function setWeeklyContribution(int $c): self { $this->weeklyContribution = $c; return $this; }
    public function getJoinedAt(): ?\DateTimeImmutable { return $this->joinedAt; }
    public function getLastActiveAt(): ?\DateTimeImmutable { return $this->lastActiveAt; }
    public function setLastActiveAt(?\DateTimeImmutable $d): self { $this->lastActiveAt = $d; return $this; }

    public function addContribution(int $amount): void
    {
        $this->contribution += $amount;
        $this->weeklyContribution += $amount;
    }
}