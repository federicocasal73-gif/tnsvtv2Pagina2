<?php

namespace App\Entity;

use App\Repository\CampusQuizQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quiz question — supports three types via `kind`:
 *   - multiple_choice: options[].correct is the index (0-based) of the right option
 *   - true_false:     options[] is normalized to ["Verdadero","Falso"]; correct is 0 or 1
 *   - short_answer:   correct is a free-text canonical answer; grading is case-insensitive
 */
#[ORM\Entity(repositoryClass: CampusQuizQuestionRepository::class)]
#[ORM\Table(name: 'campus_quiz_questions')]
class CampusQuizQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CampusQuiz::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CampusQuiz $quiz = null;

    #[ORM\Column(length: 32, options: ['default' => self::KIND_MULTIPLE_CHOICE])]
    private string $kind = self::KIND_MULTIPLE_CHOICE;

    #[ORM\Column(length: 500)]
    private string $prompt;

    /** @var string[]|null JSON array of options for multiple_choice */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    /**
     * Index of the correct option (multiple_choice), 0/1 (true_false),
     * or canonical answer string (short_answer).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $correct = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $explanation = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $points = 1;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $orden = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public const KIND_MULTIPLE_CHOICE = 'multiple_choice';
    public const KIND_TRUE_FALSE = 'true_false';
    public const KIND_SHORT_ANSWER = 'short_answer';

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getQuiz(): ?CampusQuiz { return $this->quiz; }
    public function setQuiz(?CampusQuiz $q): static { $this->quiz = $q; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getPrompt(): string { return $this->prompt; }
    public function setPrompt(string $p): static { $this->prompt = $p; return $this; }
    public function getOptions(): ?array { return $this->options; }
    public function setOptions(?array $o): static { $this->options = $o; return $this; }
    public function getCorrect(): mixed { return $this->correct; }
    public function setCorrect(mixed $c): static { $this->correct = $c; return $this; }
    public function getExplanation(): ?string { return $this->explanation; }
    public function setExplanation(?string $e): static { $this->explanation = $e; return $this; }
    public function getPoints(): int { return $this->points; }
    public function setPoints(int $p): static { $this->points = $p; return $this; }
    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $o): static { $this->orden = $o; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Returns the correct answer normalised to a string for comparison. */
    public function getCorrectString(): string
    {
        if (is_string($this->correct)) {
            return $this->correct;
        }
        if (is_int($this->correct)) {
            return (string) $this->correct;
        }
        return '';
    }
}
