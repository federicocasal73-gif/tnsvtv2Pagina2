<?php

namespace App\Entity;

use App\Repository\ModuleProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModuleProgressRepository::class)]
#[ORM\Table(name: 'module_progress')]
class ModuleProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $moduleId = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $completed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getModuleId(): ?string { return $this->moduleId; }
    public function setModuleId(string $moduleId): static { $this->moduleId = $moduleId; return $this; }

    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $completed): static { $this->completed = $completed; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }
}
