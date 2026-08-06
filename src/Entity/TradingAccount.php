<?php

namespace App\Entity;

use App\Repository\TradingAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradingAccountRepository::class)]
#[ORM\Table(name: 'trading_accounts')]
#[ORM\Index(columns: ['user_id', 'is_active', 'deleted_at'], name: 'idx_ta_user_active')]
class TradingAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => 10000])]
    private float $accountSize = 10000;

    #[ORM\Column(length: 20, options: ['default' => '#d4af37'])]
    private string $color = '#d4af37';

    #[ORM\Column(length: 20, options: ['default' => '💰'])]
    private string $icon = '💰';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getAccountSize(): float { return $this->accountSize; }
    public function setAccountSize(float $v): static { $this->accountSize = $v; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $c): static { $this->color = $c; return $this; }

    public function getIcon(): string { return $this->icon; }
    public function setIcon(string $i): static { $this->icon = $i; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function setDeletedAt(?\DateTimeImmutable $v): static { $this->deletedAt = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): static { $this->createdAt = $v; return $this; }

    public function softDelete(): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->isActive = false;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
