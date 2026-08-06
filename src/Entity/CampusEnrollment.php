<?php

namespace App\Entity;

use App\Repository\CampusEnrollmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampusEnrollmentRepository::class)]
#[ORM\Table(name: 'campus_enrollments')]
class CampusEnrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampusCourse::class, inversedBy: 'enrollments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CampusCourse $course = null;

    #[ORM\Column(length: 50)]
    private ?string $userCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $enrolledAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->enrolledAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCourse(): ?CampusCourse { return $this->course; }
    public function setCourse(?CampusCourse $course): static { $this->course = $course; return $this; }

    public function getUserCode(): ?string { return $this->userCode; }
    public function setUserCode(string $userCode): static { $this->userCode = $userCode; return $this; }

    public function getEnrolledAt(): \DateTimeImmutable { return $this->enrolledAt; }
    public function setEnrolledAt(\DateTimeImmutable $enrolledAt): static { $this->enrolledAt = $enrolledAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
