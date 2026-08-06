<?php

namespace App\Entity;

use App\Repository\JournalSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JournalSettingRepository::class)]
#[ORM\Table(name: 'journal_settings')]
class JournalSetting
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_CONNECTIONS = 'connections';
    public const VISIBILITY_PRIVATE = 'private';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?User $user = null;

    #[ORM\Column(length: 20, options: ['default' => 'public'])]
    private string $visibility = self::VISIBILITY_PUBLIC;

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): static { $this->visibility = $visibility; return $this; }
}
