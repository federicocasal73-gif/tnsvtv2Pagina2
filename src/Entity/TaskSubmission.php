<?php

namespace App\Entity;

use App\Repository\TaskSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskSubmissionRepository::class)]
#[ORM\Table(name: 'task_submissions')]
class TaskSubmission
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_REVIEW   = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVISION = 'revision';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'submissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Task $task = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** @var string|null Base64-encoded file payload (single file for simplicity) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fileData = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fileMime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTask(): ?Task { return $this->task; }
    public function setTask(?Task $t): static { $this->task = $t; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }
    public function getFileData(): ?string { return $this->fileData; }
    public function setFileData(?string $d): static { $this->fileData = $d; return $this; }
    public function getFileName(): ?string { return $this->fileName; }
    public function setFileName(?string $f): static { $this->fileName = $f; return $this; }
    public function getFileMime(): ?string { return $this->fileMime; }
    public function setFileMime(?string $m): static { $this->fileMime = $m; return $this; }
    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(?int $s): static { $this->fileSize = $s; return $this; }
    public function getComments(): ?string { return $this->comments; }
    public function setComments(?string $c): static { $this->comments = $c; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'task_id' => $this->getTask()?->getId(),
            'user_code' => $this->getUser()?->getCode(),
            'user_name' => $this->getUser()?->getName(),
            'file_name' => $this->getFileName(),
            'file_mime' => $this->getFileMime(),
            'file_size' => $this->getFileSize(),
            'file_data' => $this->getFileData(),
            'comments' => $this->getComments(),
            'status' => $this->getStatus(),
            'submitted_at' => $this->getSubmittedAt()->format('c'),
            'created_at' => $this->getCreatedAt()->format('c'),
        ];
    }
}
