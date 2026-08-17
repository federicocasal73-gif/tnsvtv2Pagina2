<?php

namespace App\Entity;

use App\Repository\CalendarEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F8 — Calendario Académico + Clases 1:1
 * Evento planificado (clase grupal, mentoring, evento, evaluación).
 */
#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\Table(name: 'calendar_events')]
#[ORM\Index(name: 'idx_ce_owner',     columns: ['owner_id'])]
#[ORM\Index(name: 'idx_ce_mentor',    columns: ['mentor_id'])]
#[ORM\Index(name: 'idx_ce_starts_at', columns: ['starts_at'])]
class CalendarEvent
{
    public const TYPE_CLASS     = 'class';
    public const TYPE_GROUP     = 'group';
    public const TYPE_ONE_ON_ONE = '1on1';
    public const TYPE_MENTORING = 'mentoring';
    public const TYPE_EVENT     = 'event';
    public const TYPE_TASK      = 'task';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE      = 'live';
    public const STATUS_DONE      = 'done';
    public const STATUS_CANCELED  = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 32)]
    private string $type = self::TYPE_CLASS;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'mentor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $mentor = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $meetingUrl = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $maxAttendees = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $currentAttendees = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $recurring = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $u): static { $this->owner = $u; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $d): static { $this->startsAt = $d; return $this; }

    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): static { $this->endsAt = $d; return $this; }

    public function getMentor(): ?User { return $this->mentor; }
    public function setMentor(?User $u): static { $this->mentor = $u; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $l): static { $this->location = $l; return $this; }

    public function getMeetingUrl(): ?string { return $this->meetingUrl; }
    public function setMeetingUrl(?string $u): static { $this->meetingUrl = $u; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $c): static { $this->color = $c; return $this; }

    public function getMaxAttendees(): int { return $this->maxAttendees; }
    public function setMaxAttendees(int $n): static { $this->maxAttendees = $n; return $this; }

    public function getCurrentAttendees(): int { return $this->currentAttendees; }
    public function setCurrentAttendees(int $n): static { $this->currentAttendees = $n; return $this; }

    public function isRecurring(): bool { return $this->recurring; }
    public function setRecurring(bool $b): static { $this->recurring = $b; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->id !== null ? (int) $this->id : null,
            'owner_code' => $this->owner?->getCode(),
            'owner_name' => $this->owner?->getName(),
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'starts_at' => $this->startsAt?->format('c'),
            'ends_at' => $this->endsAt?->format('c'),
            'mentor_code' => $this->mentor?->getCode(),
            'mentor_name' => $this->mentor?->getName(),
            'location' => $this->location,
            'meeting_url' => $this->meetingUrl,
            'status' => $this->status,
            'color' => $this->color,
            'max_attendees' => $this->maxAttendees,
            'current_attendees' => $this->currentAttendees,
            'recurring' => $this->recurring,
        ];
    }
}
