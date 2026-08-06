<?php

namespace App\Entity;

use App\Repository\CampusSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampusSubmissionRepository::class)]
#[ORM\Table(name: 'campus_submissions')]
class CampusSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampusAssignment::class, inversedBy: 'submissions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CampusAssignment $assignment = null;

    #[ORM\Column(length: 50)]
    private ?string $userCode = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $files = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'submission', targetEntity: CampusFeedback::class)]
    private ?CampusFeedback $feedback = null;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CORRECTED = 'corrected';
    public const STATUS_REVISION = 'revision';
    public const STATUS_COMPLETED = 'completed';

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAssignment(): ?CampusAssignment { return $this->assignment; }
    public function setAssignment(?CampusAssignment $assignment): static { $this->assignment = $assignment; return $this; }

    public function getUserCode(): ?string { return $this->userCode; }
    public function setUserCode(string $userCode): static { $this->userCode = $userCode; return $this; }

    public function getFiles(): ?array { return $this->files; }
    public function setFiles(?array $files): static { $this->files = $files; return $this; }

    public function getComments(): ?string { return $this->comments; }
    public function setComments(?string $comments): static { $this->comments = $comments; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getFeedback(): ?CampusFeedback { return $this->feedback; }
    public function setFeedback(?CampusFeedback $feedback): static { $this->feedback = $feedback; return $this; }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
