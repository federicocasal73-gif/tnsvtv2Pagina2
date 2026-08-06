<?php

namespace App\Entity;

use App\Repository\EventMissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Misión exclusiva de un evento especial.
 * Ej: "Ganá 5 trades durante Halloween" → recompensa: skin exclusiva.
 */
#[ORM\Entity(repositoryClass: EventMissionRepository::class)]
#[ORM\Table(name: 'event_missions')]
class EventMission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SpecialEvent::class, inversedBy: 'missions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?SpecialEvent $event = null;

    #[ORM\Column(length: 100)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30)]
    private string $type = 'trade_count'; // trade_count, profit, streak, survival, etc.

    #[ORM\Column(type: Types::JSON)]
    private array $requirements = []; // config: { count: 5, mode: 'classic' }

    #[ORM\Column(type: Types::JSON)]
    private array $rewards = []; // { coins: 500, xp: 100, items: ['skin_...'] }

    #[ORM\Column(length: 20)]
    private string $difficulty = 'medium'; // easy, medium, hard

    #[ORM\Column(type: Types::JSON)]
    private array $objectives = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: EventMissionProgress::class, cascade: ['remove'])]
    private Collection $progress;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->progress = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEvent(): ?SpecialEvent { return $this->event; }
    public function setEvent(?SpecialEvent $e): self { $this->event = $e; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): self { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): self { $this->type = $t; return $this; }
    public function getRequirements(): array { return $this->requirements; }
    public function setRequirements(array $r): self { $this->requirements = $r; return $this; }
    public function getRewards(): array { return $this->rewards; }
    public function setRewards(array $r): self { $this->rewards = $r; return $this; }
    public function getDifficulty(): string { return $this->difficulty; }
    public function setDifficulty(string $d): self { $this->difficulty = $d; return $this; }
    public function getObjectives(): array { return $this->objectives; }
    public function setObjectives(array $o): self { $this->objectives = $o; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getProgress(): Collection { return $this->progress; }

    public function isCompletedByUser(int $userId): bool
    {
        foreach ($this->progress as $p) {
            if ($p->getUser()->getId() === $userId && $p->isCompleted()) {
                return true;
            }
        }
        return false;
    }
}