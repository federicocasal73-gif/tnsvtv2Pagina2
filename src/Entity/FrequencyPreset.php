<?php

namespace App\Entity;

use App\Repository\FrequencyPresetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FrequencyPresetRepository::class)]
#[ORM\Table(name: 'frequency_presets')]
class FrequencyPreset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column]
    private int $frequency = 432; // Hz

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null; // solfeggio, healing, custom

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: 'json')]
    private array $benefits = []; // ['meditation', 'focus', ...]

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getFrequency(): int { return $this->frequency; }
    public function setFrequency(int $f): static { $this->frequency = $f; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $c): static { $this->category = $c; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }
    public function getBenefits(): array { return $this->benefits; }
    public function setBenefits(array $b): static { $this->benefits = $b; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'frequency' => $this->getFrequency(),
            'category' => $this->getCategory(),
            'description' => $this->getDescription(),
            'active' => $this->isActive(),
            'benefits' => $this->getBenefits(),
        ];
    }
}