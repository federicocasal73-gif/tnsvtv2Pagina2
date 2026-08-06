<?php

namespace App\Entity;

use App\Repository\UserFrequencyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserFrequencyRepository::class)]
#[ORM\Table(name: 'user_frequencies')]
class UserFrequency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column]
    private int $frequency = 432;

    #[ORM\Column(length: 50)]
    private string $type = 'preset'; // preset, custom_upload, custom_generated

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $filePath = null; // for uploads

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getFrequency(): int { return $this->frequency; }
    public function setFrequency(int $f): static { $this->frequency = $f; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $f): static { $this->filePath = $f; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'frequency' => $this->getFrequency(),
            'type' => $this->getType(),
            'notes' => $this->getNotes(),
            'createdAt' => $this->getCreatedAt()->format('Y-m-d H:i'),
        ];
    }
}