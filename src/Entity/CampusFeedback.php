<?php

namespace App\Entity;

use App\Repository\CampusFeedbackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampusFeedbackRepository::class)]
#[ORM\Table(name: 'campus_feedbacks')]
class CampusFeedback
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'feedback', targetEntity: CampusSubmission::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?CampusSubmission $submission = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1, nullable: true)]
    private ?string $grade = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

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

    public function getSubmission(): ?CampusSubmission { return $this->submission; }
    public function setSubmission(?CampusSubmission $submission): static { $this->submission = $submission; return $this; }

    public function getGrade(): ?string { return $this->grade; }
    public function setGrade(?string $grade): static { $this->grade = $grade; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }

    public function getGradedAt(): \DateTimeImmutable { return $this->gradedAt; }
    public function setGradedAt(\DateTimeImmutable $gradedAt): static { $this->gradedAt = $gradedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
