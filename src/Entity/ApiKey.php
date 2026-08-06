<?php

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_keys')]
#[ORM\Index(name: 'idx_ak_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uq_ak_prefix', columns: ['key_prefix'])]
class ApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $keyHash = '';

    #[ORM\Column(length: 20)]
    private string $keyPrefix = '';

    #[ORM\Column(length: 100)]
    private string $label = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getKeyHash(): string { return $this->keyHash; }
    public function setKeyHash(string $hash): static { $this->keyHash = $hash; return $this; }

    public function getKeyPrefix(): string { return $this->keyPrefix; }
    public function setKeyPrefix(string $prefix): static { $this->keyPrefix = $prefix; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeImmutable $v): static { $this->lastUsedAt = $v; return $this; }

    public function touchLastUsed(): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
