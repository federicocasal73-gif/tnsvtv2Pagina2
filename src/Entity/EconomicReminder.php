<?php

namespace App\Entity;

use App\Repository\EconomicReminderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EconomicReminderRepository::class)]
#[ORM\Table(name: 'economic_reminders')]
#[ORM\Index(columns: ['status'], name: 'idx_er_status')]
#[ORM\Index(columns: ['remind_at'], name: 'idx_er_remind_at')]
class EconomicReminder
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_FIRED = 'fired';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private ?string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 10)]
    private ?string $eventDate = null;

    #[ORM\Column(length: 5)]
    private ?string $eventTime = null;

    #[ORM\Column(length: 10)]
    private ?string $timezone = 'America/Argentina/Buenos_Aires';

    #[ORM\Column(length: 200)]
    private ?string $eventTitle = null;

    #[ORM\Column(length: 100)]
    private ?string $eventTitleOriginal = null;

    #[ORM\Column(length: 10)]
    private ?string $eventCountryCode = null;

    #[ORM\Column(length: 10)]
    private ?string $eventCurrency = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 3])]
    private int $eventImportance = 3;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $remindAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getEventDate(): ?string { return $this->eventDate; }
    public function setEventDate(string $eventDate): static { $this->eventDate = $eventDate; return $this; }

    public function getEventTime(): ?string { return $this->eventTime; }
    public function setEventTime(string $eventTime): static { $this->eventTime = $eventTime; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(string $tz): static { $this->timezone = $tz; return $this; }

    public function getEventTitle(): ?string { return $this->eventTitle; }
    public function setEventTitle(?string $t): static { $this->eventTitle = $t; return $this; }

    public function getEventTitleOriginal(): ?string { return $this->eventTitleOriginal; }
    public function setEventTitleOriginal(?string $t): static { $this->eventTitleOriginal = $t; return $this; }

    public function getEventCountryCode(): ?string { return $this->eventCountryCode; }
    public function setEventCountryCode(?string $c): static { $this->eventCountryCode = $c; return $this; }

    public function getEventCurrency(): ?string { return $this->eventCurrency; }
    public function setEventCurrency(?string $c): static { $this->eventCurrency = $c; return $this; }

    public function getEventImportance(): int { return $this->eventImportance; }
    public function setEventImportance(int $i): static { $this->eventImportance = $i; return $this; }

    public function getRemindAt(): ?\DateTimeImmutable { return $this->remindAt; }
    public function setRemindAt(\DateTimeImmutable $d): static { $this->remindAt = $d; return $this; }

    public function getFiredAt(): ?\DateTimeImmutable { return $this->firedAt; }
    public function setFiredAt(?\DateTimeImmutable $d): static { $this->firedAt = $d; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }

    public function markFired(): static
    {
        $this->status = self::STATUS_FIRED;
        $this->firedAt = new \DateTimeImmutable();
        return $this;
    }

    public function cancel(): static
    {
        $this->status = self::STATUS_CANCELLED;
        return $this;
    }
}
