<?php

namespace App\Entity;

use App\Repository\AchievementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * TNSVT Achievement — Fase 5.
 *
 * A predefined achievement definition. The UserAchievement entity is
 * the join entity recording which user has unlocked which achievement at
 * which time. The criterion (slug) is evaluated by AchievementService
 * against user activity (lessons completed, streak length, etc).
 */
#[ORM\Entity(repositoryClass: AchievementRepository::class)]
#[ORM\Table(name: 'achievements')]
#[ORM\UniqueConstraint(name: 'achievement_slug_unique', columns: ['slug'])]
class Achievement
{
    public const CATEGORY_LEARNING = 'learning';
    public const CATEGORY_STREAK = 'streak';
    public const CATEGORY_MASTERY = 'mastery';
    public const CATEGORY_COMMUNITY = 'community';
    public const CATEGORY_SPECIAL = 'special';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable identifier used by code (e.g. 'first-lesson'). */
    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Material icon name (e.g. 'emoji_events', 'whatshot'). */
    #[ORM\Column(length: 64, options: ['default' => 'emoji_events'])]
    private string $icon = 'emoji_events';

    /** Visual style: gold | violet | emerald | fire | ice */
    #[ORM\Column(length: 32, options: ['default' => 'gold'])]
    private string $color = 'gold';

    /** Numeric points awarded (gameification). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 10])]
    private int $points = 10;

    #[ORM\Column(length: 32, options: ['default' => self::CATEGORY_LEARNING])]
    private string $category = self::CATEGORY_LEARNING;

    /** Threshold type — 'count', 'streak', 'mastery', 'special'. */
    #[ORM\Column(length: 32, options: ['default' => 'count'])]
    private string $criterionType = 'count';

    /** Threshold value (e.g. 1 lesson, 7 day streak, 100 lessons). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $criterionValue = 1;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $orden = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $i): static { $this->icon = $i; return $this; }
    public function getColor(): string { return $this->color; }
    public function setColor(string $c): static { $this->color = $c; return $this; }
    public function getPoints(): int { return $this->points; }
    public function setPoints(int $p): static { $this->points = $p; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $c): static { $this->category = $c; return $this; }
    public function getCriterionType(): string { return $this->criterionType; }
    public function setCriterionType(string $t): static { $this->criterionType = $t; return $this; }
    public function getCriterionValue(): int { return $this->criterionValue; }
    public function setCriterionValue(int $v): static { $this->criterionValue = $v; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }
    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $o): static { $this->orden = $o; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
