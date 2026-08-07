<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Setting — global configuration for the Sanctum.
 *
 * Single-row-per-key (key is unique). Used for feature flags,
 * tier pricing, message strings, etc. that admins can edit from
 * the Settings UI.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'settings')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', length: 100)]
    private string $key = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string { return $this->key; }
    public function setKey(string $k): static { $this->key = $k; return $this; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $v): static { $this->value = $v; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $c): static { $this->category = $c; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }

    /**
     * Get value cast to bool (for feature flags).
     */
    public function getBoolValue(): bool
    {
        $v = strtolower(trim((string)$this->value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}