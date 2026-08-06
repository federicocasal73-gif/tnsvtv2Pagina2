<?php

namespace App\Entity;

use App\Repository\PropFirmRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PropFirmRepository::class)]
#[ORM\Table(name: 'prop_firms')]
class PropFirm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::JSON)]
    private array $rules = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = strtoupper($code); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getRules(): array { return $this->rules; }
    public function setRules(array $rules): static { $this->rules = $rules; return $this; }

    public function getRule(string $key, mixed $default = null): mixed
    {
        return $this->rules[$key] ?? $default;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
