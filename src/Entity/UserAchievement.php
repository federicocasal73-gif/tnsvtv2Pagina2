<?php

namespace App\Entity;

use App\Repository\UserAchievementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserAchievementRepository::class)]
#[ORM\Table(name: 'user_achievements')]
#[ORM\UniqueConstraint(name: 'user_achievement_unique', columns: ['user_code', 'achievement_id'])]
class UserAchievement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $userCode;

    #[ORM\ManyToOne(targetEntity: Achievement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Achievement $achievement = null;

    /** Snapshot of the user's stat at unlock time (e.g. streak=7). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $unlockedAt;

    public function __construct()
    {
        $this->unlockedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUserCode(): string { return $this->userCode; }
    public function setUserCode(string $u): static { $this->userCode = $u; return $this; }
    public function getAchievement(): ?Achievement { return $this->achievement; }
    public function setAchievement(?Achievement $a): static { $this->achievement = $a; return $this; }
    public function getContext(): ?array { return $this->context; }
    public function setContext(?array $c): static { $this->context = $c; return $this; }
    public function getUnlockedAt(): \DateTimeImmutable { return $this->unlockedAt; }
}
