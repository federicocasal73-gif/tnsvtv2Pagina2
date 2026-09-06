<?php

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * TNSVT Task — Fase 3 unified task model.
 *
 * Replaces the legacy "global checklist" Task entity with a rich model
 * that supports both the mentor/admin task list AND the student-facing
 * assignment flow. CampusAssignment remains a separate lesson-scoped
 * specialization but reuses the same status vocabulary.
 *
 * 7 canonical status states:
 *   - pending          (created, not started)
 *   - in_progress      (student started working on it)
 *   - submitted        (student submitted, awaiting review)
 *   - in_review        (mentor is grading)
 *   - approved         (mentor approved, completed)
 *   - needs_revision   (mentor rejected, must be redone)
 *   - overdue          (computed by cron: due_date < now AND not approved)
 *
 * 4 priority levels: low, normal, high, urgent
 *
 * Activity log (TaskComment) supports threaded conversation per task.
 * Feedback (TaskFeedback) captures the mentor's grade + final comment.
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'tasks')]
#[ORM\Index(name: 'idx_task_assigned_status', columns: ['assigned_to_id', 'status'])]
#[ORM\Index(name: 'idx_task_due_date', columns: ['due_date'])]
#[ORM\Index(name: 'idx_task_course', columns: ['course_id'])]
class Task
{
    public const STATUS_PENDING       = 'pending';
    public const STATUS_IN_PROGRESS   = 'in_progress';
    public const STATUS_SUBMITTED     = 'submitted';
    public const STATUS_IN_REVIEW     = 'in_review';
    public const STATUS_APPROVED      = 'approved';
    public const STATUS_NEEDS_REVISION = 'needs_revision';
    public const STATUS_OVERDUE       = 'overdue';

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const TYPE_GENERIC = 'general';
    public const TYPE_ACADEMIC = 'academic';
    public const TYPE_PERSONAL = 'personal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 32, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 16, options: ['default' => self::PRIORITY_NORMAL])]
    private string $priority = self::PRIORITY_NORMAL;

    #[ORM\Column(length: 32, options: ['default' => self::TYPE_GENERIC])]
    private string $type = self::TYPE_GENERIC;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy = null;

    #[ORM\ManyToOne(targetEntity: CampusCourse::class)]
    #[ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CampusCourse $course = null;

    #[ORM\ManyToOne(targetEntity: CampusLesson::class)]
    #[ORM\JoinColumn(name: 'lesson_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CampusLesson $lesson = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $links = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attachments = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $orden = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\OneToMany(mappedBy: 'task', targetEntity: TaskSubmission::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $submissions;

    #[ORM\OneToMany(mappedBy: 'task', targetEntity: TaskComment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $comments;

    #[ORM\OneToOne(mappedBy: 'task', targetEntity: TaskFeedback::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?TaskFeedback $feedback = null;

    public function __construct()
    {
        $this->submissions = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $priority): static { $this->priority = $priority; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $u): static { $this->assignedTo = $u; return $this; }
    public function getAssignedBy(): ?User { return $this->assignedBy; }
    public function setAssignedBy(?User $u): static { $this->assignedBy = $u; return $this; }
    public function getCourse(): ?CampusCourse { return $this->course; }
    public function setCourse(?CampusCourse $c): static { $this->course = $c; return $this; }
    public function getLesson(): ?CampusLesson { return $this->lesson; }
    public function setLesson(?CampusLesson $l): static { $this->lesson = $l; return $this; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $d): static { $this->dueDate = $d; return $this; }
    public function getEstimatedMinutes(): ?int { return $this->estimatedMinutes; }
    public function setEstimatedMinutes(?int $m): static { $this->estimatedMinutes = $m; return $this; }
    public function getInstructions(): ?string { return $this->instructions; }
    public function setInstructions(?string $i): static { $this->instructions = $i; return $this; }
    public function getLinks(): ?array { return $this->links; }
    public function setLinks(?array $l): static { $this->links = $l; return $this; }
    public function getAttachments(): ?array { return $this->attachments; }
    public function setAttachments(?array $a): static { $this->attachments = $a; return $this; }
    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $o): static { $this->orden = $o; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $c): static { $this->createdAt = $c; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $u): static { $this->updatedAt = $u; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $c): static { $this->completedAt = $c; return $this; }
    public function getSubmissions(): Collection { return $this->submissions; }
    public function getComments(): Collection { return $this->comments; }
    public function getFeedback(): ?TaskFeedback { return $this->feedback; }
    public function setFeedback(?TaskFeedback $f): static { $this->feedback = $f; return $this; }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** Mark the task as approved + completed. */
    public function markApproved(): static
    {
        $this->status = self::STATUS_APPROVED;
        $this->completedAt = new \DateTimeImmutable();
        $this->touch();
        return $this;
    }

    /** Mark the task as in-progress. */
    public function markInProgress(): static
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->touch();
        return $this;
    }

    /** Mark the task as submitted by the student. */
    public function markSubmitted(): static
    {
        $this->status = self::STATUS_SUBMITTED;
        $this->touch();
        return $this;
    }

    /** Mark the task as needing revision. */
    public function markNeedsRevision(): static
    {
        $this->status = self::STATUS_NEEDS_REVISION;
        $this->touch();
        return $this;
    }

    /** Returns true if the task is overdue (due_date in past and not approved). */
    public function isOverdue(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->dueDate) return false;
        if ($this->status === self::STATUS_APPROVED) return false;
        $now ??= new \DateTimeImmutable();
        return $this->dueDate < $now;
    }

    /** Returns a label for the current status (Spanish). */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING       => 'Pendiente',
            self::STATUS_IN_PROGRESS   => 'En progreso',
            self::STATUS_SUBMITTED     => 'Entregada',
            self::STATUS_IN_REVIEW     => 'En revisión',
            self::STATUS_APPROVED      => 'Aprobada',
            self::STATUS_NEEDS_REVISION => 'Necesita corrección',
            self::STATUS_OVERDUE       => 'Vencida',
            default                    => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /** Returns a label for the current priority (Spanish). */
    public function getPriorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_LOW    => 'Baja',
            self::PRIORITY_NORMAL => 'Normal',
            self::PRIORITY_HIGH   => 'Alta',
            self::PRIORITY_URGENT => 'Urgente',
            default               => ucfirst($this->priority),
        };
    }

    /** Serializes the task for API responses. */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'status' => $this->getStatus(),
            'status_label' => $this->getStatusLabel(),
            'priority' => $this->getPriority(),
            'priority_label' => $this->getPriorityLabel(),
            'type' => $this->getType(),
            'assigned_to' => $this->getAssignedTo()?->getCode(),
            'assigned_by' => $this->getAssignedBy()?->getCode(),
            'course_id' => $this->getCourse()?->getId(),
            'lesson_id' => $this->getLesson()?->getId(),
            'due_date' => $this->getDueDate()?->format('c'),
            'is_overdue' => $this->isOverdue(),
            'estimated_minutes' => $this->getEstimatedMinutes(),
            'instructions' => $this->getInstructions(),
            'links' => $this->getLinks() ?? [],
            'attachments' => $this->getAttachments() ?? [],
            'orden' => $this->getOrden(),
            'active' => $this->isActive(),
            'created_at' => $this->getCreatedAt()->format('c'),
            'updated_at' => $this->getUpdatedAt()->format('c'),
            'completed_at' => $this->getCompletedAt()?->format('c'),
            'submissions_count' => $this->getSubmissions()->count(),
            'comments_count' => $this->getComments()->count(),
            'has_feedback' => $this->getFeedback() !== null,
        ];
    }
}
