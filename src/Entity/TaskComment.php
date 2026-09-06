<?php

namespace App\Entity;

use App\Repository\TaskCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskCommentRepository::class)]
#[ORM\Table(name: 'task_comments')]
#[ORM\Index(name: 'idx_task_comment_task', columns: ['task_id', 'created_at'])]
class TaskComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Task $task = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 32, options: ['default' => 'comment'])]
    private string $kind = 'comment';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTask(): ?Task { return $this->task; }
    public function setTask(?Task $t): static { $this->task = $t; return $this; }
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $u): static { $this->author = $u; return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $b): static { $this->body = $b; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEditedAt(): ?\DateTimeImmutable { return $this->editedAt; }
    public function setEditedAt(?\DateTimeImmutable $e): static { $this->editedAt = $e; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'task_id' => $this->getTask()?->getId(),
            'author_code' => $this->getAuthor()?->getCode(),
            'author_name' => $this->getAuthor()?->getName(),
            'body' => $this->getBody(),
            'kind' => $this->getKind(),
            'created_at' => $this->getCreatedAt()->format('c'),
            'edited_at' => $this->getEditedAt()?->format('c'),
        ];
    }
}
