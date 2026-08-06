<?php

namespace App\Entity;

use App\Repository\FrequencySessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FrequencySessionRepository::class)]
#[ORM\Table(name: 'frequency_sessions')]
class FrequencySession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: FrequencyPreset::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?FrequencyPreset $preset = null;

    #[ORM\ManyToOne(targetEntity: UserFrequency::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?UserFrequency $userFrequency = null;

    #[ORM\Column]
    private int $durationMinutes = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column]
    private bool $completed = false;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getPreset(): ?FrequencyPreset { return $this->preset; }
    public function setPreset(?FrequencyPreset $p): static { $this->preset = $p; return $this; }
    public function getUserFrequency(): ?UserFrequency { return $this->userFrequency; }
    public function setUserFrequency(?UserFrequency $uf): static { $this->userFrequency = $uf; return $this; }
    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $d): static { $this->durationMinutes = $d; return $this; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function setEndedAt(?\DateTimeImmutable $e): static { $this->endedAt = $e; return $this; }
    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $c): static { $this->completed = $c; return $this; }
}