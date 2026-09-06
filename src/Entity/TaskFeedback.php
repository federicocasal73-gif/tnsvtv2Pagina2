<?php

namespace App\Entity;

use App\Repository\TaskFeedbackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskFeedbackRepository::class)]
#[ORM\Table(name: 'task_feedback')]
class TaskFeedback
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'feedback', targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Task $task = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'grader_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $grader = null;

    /** DECIMAL(4,2) — 0.00 to 10.00 */
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 2, nullable: true)]
    private ?string $grade = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 32, options: ['default' => 'graded'])]
    private string $decision = 'graded';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $gradedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->gradedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTask(): ?Task { return $this->task; }
    public function setTask(?Task $t): static { $this->task = $t; return $this; }
    public function getGrader(): ?User { return $this->grader; }
    public function setGrader(?User $u): static { $this->grader = $u; return $this; }
    public function getGrade(): ?string { return $this->grade; }
    public function setGrade(?string $g): static { $this->grade = $g; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $c): static { $this->comment = $c; return $this; }
    public function getDecision(): string { return $this->decision; }
    public function setDecision(string $d): static { $this->decision = $d; return $this; }
    public function getGradedAt(): \DateTimeImmutable { return $this->gradedAt; }
    public function setGradedAt(\DateTimeImmutable $g): static { $this->gradedAt = $g; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'task_id' => $this->getTask()?->getId(),
            'grader_code' => $this->getGrader()?->getCode(),
            'grader_name' => $this->getGrader()?->getName(),
            'grade' => $this->getGrade(),
            'comment' => $this->getComment(),
            'decision' => $this->getDecision(),
            'graded_at' => $this->getGradedAt()->format('c'),
        ];
    }
}
