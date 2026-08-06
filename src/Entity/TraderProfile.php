<?php

namespace App\Entity;

use App\Repository\TraderProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TraderProfileRepository::class)]
#[ORM\Table(name: 'trader_profiles')]
class TraderProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $strategy = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $style = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $favoritePairs = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $riskPerTrade = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $experience = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extraNotes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }
    public function getStrategy(): ?string { return $this->strategy; }
    public function setStrategy(?string $s): static { $this->strategy = $s; return $this; }
    public function getStyle(): ?string { return $this->style; }
    public function setStyle(?string $s): static { $this->style = $s; return $this; }
    public function getFavoritePairs(): ?string { return $this->favoritePairs; }
    public function setFavoritePairs(?string $s): static { $this->favoritePairs = $s; return $this; }
    public function getRiskPerTrade(): ?string { return $this->riskPerTrade; }
    public function setRiskPerTrade(?string $v): static { $this->riskPerTrade = $v; return $this; }
    public function getExperience(): ?string { return $this->experience; }
    public function setExperience(?string $s): static { $this->experience = $s; return $this; }
    public function getExtraNotes(): ?string { return $this->extraNotes; }
    public function setExtraNotes(?string $s): static { $this->extraNotes = $s; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }
}
