<?php

namespace App\Entity;

use App\Repository\CampusLessonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampusLessonRepository::class)]
#[ORM\Table(name: 'campus_lessons')]
class CampusLesson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampusModule::class, inversedBy: 'lessons')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CampusModule $module = null;

    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: CampusAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $assignments;

    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: CampusMaterial::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $materials;

    #[ORM\OneToMany(mappedBy: 'lesson', targetEntity: CampusLessonProgress::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $progressRecords;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $videoUrl = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $orden = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
        $this->materials = new ArrayCollection();
        $this->progressRecords = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getModule(): ?CampusModule { return $this->module; }
    public function setModule(?CampusModule $module): static { $this->module = $module; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getVideoUrl(): ?string { return $this->videoUrl; }
    public function setVideoUrl(?string $videoUrl): static { $this->videoUrl = $videoUrl; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): static { $this->orden = $orden; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getAssignments(): Collection { return $this->assignments; }
    public function getMaterials(): Collection { return $this->materials; }
    public function getProgressRecords(): Collection { return $this->progressRecords; }
}
