<?php

namespace App\Entity;

use App\Repository\EventMissionProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progreso de un usuario en una misión de evento.
 */
#[ORM\Entity(repositoryClass: EventMissionProgressRepository::class)]
#[ORM\Table(name: 'event_mission_progress')]
class EventMissionProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EventMission::class, inversedBy: 'progress')]
    #[ORM\JoinColumn(nullable: false)]
    private ?EventMission $mission = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::JSON)]
    private array $progress = []; // { current: 3, target: 5 }

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column]
    private bool $claimed = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->progress = ['current' => 0, 'target' => 0];
    }

    public function getId(): ?int { return $this->id; }
    public function getMission(): ?EventMission { return $this->mission; }
    public function setMission(?EventMission $m): self { $this->mission = $m; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getProgress(): array { return $this->progress; }
    public function setProgress(array $p): self { $this->progress = $p; return $this; }
    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $c): self { $this->completed = $c; return $this; }
    public function isClaimed(): bool { return $this->claimed; }
    public function setClaimed(bool $c): self { $this->claimed = $c; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $d): self { $this->completedAt = $d; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function updateProgress(int $current): void
    {
        $target = $this->progress['target'] ?? 1;
        $this->progress['current'] = min($current, $target);
        if ($this->progress['current'] >= $target && !$this->completed) {
            $this->completed = true;
            $this->completedAt = new \DateTimeImmutable();
        }
    }
}