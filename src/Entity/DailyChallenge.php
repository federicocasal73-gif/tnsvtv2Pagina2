<?php

namespace App\Entity;

use App\Repository\DailyChallengeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Desafío diario competitivo.
 * Todos los jugadores enfrentan el mismo challenge con un solo intento.
 * Se resetea cada día a medianoche UTC.
 */
#[ORM\Entity(repositoryClass: DailyChallengeRepository::class)]
#[ORM\Table(name: 'daily_challenges')]
class DailyChallenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $type = 'score'; // score, speed, accuracy, streak

    #[ORM\Column(length: 32)]
    private string $date = ''; // YYYY-MM-DD

    #[ORM\Column(length: 20)]
    private string $mode = 'classic';

    #[ORM\Column(type: Types::JSON)]
    private array $config = []; // params específicas del challenge

    #[ORM\Column(type: Types::JSON)]
    private array $rewards = []; // {1: {coins, xp, title}, 2: {...}, 3: {...}}

    #[ORM\Column]
    private int $maxParticipants = 0; // 0 = unlimited

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): self { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): self { $this->type = $t; return $this; }
    public function getDate(): string { return $this->date; }
    public function setDate(string $d): self { $this->date = $d; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $m): self { $this->mode = $m; return $this; }
    public function getConfig(): array { return $this->config; }
    public function setConfig(array $c): self { $this->config = $c; return $this; }
    public function getRewards(): array { return $this->rewards; }
    public function setRewards(array $r): self { $this->rewards = $r; return $this; }
    public function getMaxParticipants(): int { return $this->maxParticipants; }
    public function setMaxParticipants(int $m): self { $this->maxParticipants = $m; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function isToday(): bool
    {
        return $this->date === (new \DateTime())->format('Y-m-d');
    }
}