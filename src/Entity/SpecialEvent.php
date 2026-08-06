<?php

namespace App\Entity;

use App\Repository\SpecialEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evento especial: Halloween, Navidad, Año Nuevo, etc.
 * Durante el evento hay challenges únicos, items exclusivos y recompensas especiales.
 */
#[ORM\Entity(repositoryClass: SpecialEventRepository::class)]
#[ORM\Table(name: 'special_events')]
class SpecialEvent
{
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FINISHED = 'finished';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(length: 50)]
    private string $theme = ''; // halloween, christmas, newyear, summer, easter, etc.

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $banner = null; // URL del banner

    #[ORM\Column(length: 10)]
    private string $emoji = '🎉';

    #[ORM\Column]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_UPCOMING;

    #[ORM\Column(type: Types::JSON)]
    private array $config = []; // configuración del evento

    #[ORM\Column(type: Types::JSON)]
    private array $shopItems = []; // items exclusivos del evento

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'event', targetEntity: EventMission::class, cascade: ['remove'])]
    private Collection $missions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->missions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }
    public function getTheme(): string { return $this->theme; }
    public function setTheme(string $t): self { $this->theme = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getBanner(): ?string { return $this->banner; }
    public function setBanner(?string $b): self { $this->banner = $b; return $this; }
    public function getEmoji(): string { return $this->emoji; }
    public function setEmoji(string $e): self { $this->emoji = $e; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(?\DateTimeImmutable $d): self { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $d): self { $this->endDate = $d; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getConfig(): array { return $this->config; }
    public function setConfig(array $c): self { $this->config = $c; return $this; }
    public function getShopItems(): array { return $this->shopItems; }
    public function setShopItems(array $s): self { $this->shopItems = $s; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getMissions(): Collection { return $this->missions; }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isUpcoming(): bool
    {
        return $this->status === self::STATUS_UPCOMING;
    }

    public function getDaysRemaining(): int
    {
        if (!$this->endDate) return 0;
        $now = new \DateTimeImmutable();
        if ($now > $this->endDate) return 0;
        return (int) $this->endDate->diff($now)->days;
    }

    public function getProgress(): float
    {
        if (!$this->startDate || !$this->endDate) return 0;
        $now = new \DateTimeImmutable();
        $total = $this->endDate->getTimestamp() - $this->startDate->getTimestamp();
        $elapsed = $now->getTimestamp() - $this->startDate->getTimestamp();
        return min(($elapsed / $total) * 100, 100);
    }
}