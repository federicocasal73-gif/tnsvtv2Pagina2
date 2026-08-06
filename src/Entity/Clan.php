<?php

namespace App\Entity;

use App\Repository\ClanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Clan: grupo de hasta 10 jugadores con chat y objetivos compartidos.
 */
#[ORM\Entity(repositoryClass: ClanRepository::class)]
#[ORM\Table(name: 'clans')]
class Clan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $name = '';

    #[ORM\Column(length: 10)]
    private string $tag = ''; // Ej: [TNSV]

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $avatar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $leader = null;

    #[ORM\Column]
    private int $maxMembers = 10;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'clan', targetEntity: ClanMember::class, cascade: ['remove'])]
    private Collection $members;

    #[ORM\OneToMany(mappedBy: 'clan', targetEntity: ClanObjective::class, cascade: ['remove'])]
    private Collection $objectives;

    #[ORM\OneToMany(mappedBy: 'clan', targetEntity: ClanMessage::class, cascade: ['remove'])]
    private Collection $messages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
        $this->objectives = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }
    public function getTag(): string { return $this->tag; }
    public function setTag(string $t): self { $this->tag = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getAvatar(): ?string { return $this->avatar; }
    public function setAvatar(?string $a): self { $this->avatar = $a; return $this; }
    public function getLeader(): ?User { return $this->leader; }
    public function setLeader(?User $u): self { $this->leader = $u; return $this; }
    public function getMaxMembers(): int { return $this->maxMembers; }
    public function setMaxMembers(int $m): self { $this->maxMembers = $m; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getMembers(): Collection { return $this->members; }
    public function getObjectives(): Collection { return $this->objectives; }
    public function getMessages(): Collection { return $this->messages; }

    public function getMemberCount(): int
    {
        return $this->members->count();
    }

    public function isFull(): bool
    {
        return $this->members->count() >= $this->maxMembers;
    }

    public function isMember(User $user): bool
    {
        foreach ($this->members as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function getMemberRole(User $user): ?string
    {
        foreach ($this->members as $member) {
            if ($member->getUser()->getId() === $user->getId()) {
                return $member->getRole();
            }
        }
        return null;
    }
}