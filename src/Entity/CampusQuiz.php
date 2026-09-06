<?php

namespace App\Entity;

use App\Repository\CampusQuizRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * TNSVT Quiz — Fase 4 evaluation entity.
 *
 * 1 quiz per lesson (optional). Contains 1+ questions; each question has
 * 2-4 options with exactly one correct. Auto-graded by CampusQuizSubmission.
 */
#[ORM\Entity(repositoryClass: CampusQuizRepository::class)]
#[ORM\Table(name: 'campus_quizzes')]
class CampusQuiz
{
    public const QUESTION_TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    public const QUESTION_TYPE_TRUE_FALSE = 'true_false';
    public const QUESTION_TYPE_SHORT_ANSWER = 'short_answer';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: CampusLesson::class)]
    #[ORM\JoinColumn(name: 'lesson_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CampusLesson $lesson = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 70])]
    private int $passingScore = 70;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $timeLimitMinutes = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $maxAttempts = 1;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $shuffleQuestions = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: CampusQuizQuestion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC', 'id' => 'ASC'])]
    private Collection $questions;

    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: CampusQuizSubmission::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $submissions;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->submissions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLesson(): ?CampusLesson { return $this->lesson; }
    public function setLesson(?CampusLesson $l): static { $this->lesson = $l; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getPassingScore(): int { return $this->passingScore; }
    public function setPassingScore(int $s): static { $this->passingScore = $s; return $this; }
    public function getTimeLimitMinutes(): ?int { return $this->timeLimitMinutes; }
    public function setTimeLimitMinutes(?int $t): static { $this->timeLimitMinutes = $t; return $this; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $m): static { $this->maxAttempts = $m; return $this; }
    public function getShuffleQuestions(): bool { return $this->shuffleQuestions; }
    public function setShuffleQuestions(bool $s): static { $this->shuffleQuestions = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getQuestions(): Collection { return $this->questions; }
    public function getSubmissions(): Collection { return $this->submissions; }
}
