<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MacroQuestionnaireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * TNSVT Sprint C.5 — Cuestionario Macro.
 *
 * Almacena las respuestas del usuario al cuestionario de perfil de riesgo.
 * Cada usuario tiene UNA fila por tipo de cuestionario.
 */
#[ORM\Entity(repositoryClass: MacroQuestionnaireRepository::class)]
#[ORM\Table(name: 'macro_questionnaires')]
#[ORM\UniqueConstraint(name: 'uniq_user_type', columns: ['user_id', 'questionnaire_type'])]
class MacroQuestionnaire
{
    public const TYPE_RISK_PROFILE = 'risk_profile';
    public const TYPE_MARKET_KNOWLEDGE = 'market_knowledge';

    public const VALID_TYPES = [
        self::TYPE_RISK_PROFILE,
        self::TYPE_MARKET_KNOWLEDGE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    private string $questionnaireType = self::TYPE_RISK_PROFILE;

    /**
     * JSON con las respuestas: { "q1": "value", "q2": "value", ... }
     * Se almacena como JSON en MySQL.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    /**
     * Score calculado (0-100). Riesgo bajo = score bajo, riesgo alto = score alto.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $score = 0;

    /**
     * Tier calculado en base al score:
     * - score < 25: conservative
     * - score 25-50: moderate
     * - score 50-75: aggressive
     * - score >= 75: degen
     */
    #[ORM\Column(length: 32, options: ['default' => 'moderate'])]
    private string $tier = 'moderate';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getQuestionnaireType(): string
    {
        return $this->questionnaireType;
    }

    public function setQuestionnaireType(string $type): static
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de cuestionario inválido: $type");
        }
        $this->questionnaireType = $type;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function setAnswers(array $answers): static
    {
        $this->answers = $answers;
        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = max(0, min(100, $score));
        $this->tier = $this->computeTier($this->score);
        return $this;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    private function computeTier(int $score): string
    {
        return match (true) {
            $score < 25  => 'conservative',
            $score < 50  => 'moderate',
            $score < 75  => 'aggressive',
            default      => 'degen',
        };
    }
}
