<?php

namespace App\Entity;

use App\Repository\CampusQuizSubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt at a quiz by a student. Stores raw answers + computed score.
 */
#[ORM\Entity(repositoryClass: CampusQuizSubmissionRepository::class)]
#[ORM\Table(name: 'campus_quiz_submissions')]
class CampusQuizSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampusQuiz::class, inversedBy: 'submissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CampusQuiz $quiz = null;

    #[ORM\Column(length: 50)]
    private ?string $userCode = null;

    /** @var array<int, mixed> JSON: question_id => student's answer */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answers = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $score = null;

    /** Max possible score for this submission (sum of question.points). */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $maxScore = null;

    /** Percentage 0–100. NULL until graded. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $percent = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $passed = false;

    /** Time taken in seconds (0 if untimed). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $durationSeconds = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getQuiz(): ?CampusQuiz { return $this->quiz; }
    public function setQuiz(?CampusQuiz $q): static { $this->quiz = $q; return $this; }
    public function getUserCode(): ?string { return $this->userCode; }
    public function setUserCode(?string $u): static { $this->userCode = $u; return $this; }
    public function getAnswers(): ?array { return $this->answers; }
    public function setAnswers(?array $a): static { $this->answers = $a; return $this; }
    public function getScore(): ?string { return $this->score; }
    public function setScore(?string $s): static { $this->score = $s; return $this; }
    public function getMaxScore(): ?string { return $this->maxScore; }
    public function setMaxScore(?string $m): static { $this->maxScore = $m; return $this; }
    public function getPercent(): ?int { return $this->percent; }
    public function setPercent(?int $p): static { $this->percent = $p; return $this; }
    public function isPassed(): bool { return $this->passed; }
    public function setPassed(bool $p): static { $this->passed = $p; return $this; }
    public function getDurationSeconds(): int { return $this->durationSeconds; }
    public function setDurationSeconds(int $d): static { $this->durationSeconds = $d; return $this; }
    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
}
