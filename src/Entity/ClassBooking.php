<?php

namespace App\Entity;

use App\Repository\ClassBookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F8 — Reserva de clase 1:1 entre un alumno (student) y un mentor.
 * Status:
 *   - pending    : creada por el alumno, esperando respuesta
 *   - accepted   : mentor aceptó
 *   - declined   : mentor rechazó
 *   - proposed   : mentor propuso horarios alternativos (guardados en proposed_times como JSON)
 *   - canceled   : cancelada por alumno o mentor
 */
#[ORM\Entity(repositoryClass: ClassBookingRepository::class)]
#[ORM\Table(name: 'class_bookings')]
#[ORM\Index(name: 'idx_cb_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_cb_mentor',  columns: ['mentor_id'])]
#[ORM\Index(name: 'idx_cb_status',  columns: ['status'])]
#[ORM\Index(name: 'idx_cb_start',   columns: ['start_at'])]
class ClassBooking
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_PROPOSED  = 'proposed';
    public const STATUS_CANCELED  = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'mentor_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $mentor = null;

    #[ORM\ManyToOne(targetEntity: CalendarEvent::class)]
    #[ORM\JoinColumn(name: 'event_id', nullable: true, onDelete: 'SET NULL')]
    private ?CalendarEvent $event = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 30])]
    private int $durationMinutes = 30;

    #[ORM\Column(length: 200)]
    private string $topic = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $proposedTimes = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $meetingUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }

    public function getStudent(): ?User { return $this->student; }
    public function setStudent(?User $u): static { $this->student = $u; return $this; }

    public function getMentor(): ?User { return $this->mentor; }
    public function setMentor(?User $u): static { $this->mentor = $u; return $this; }

    public function getEvent(): ?CalendarEvent { return $this->event; }
    public function setEvent(?CalendarEvent $e): static { $this->event = $e; return $this; }

    public function getStartAt(): ?\DateTimeImmutable { return $this->startAt; }
    public function setStartAt(?\DateTimeImmutable $d): static { $this->startAt = $d; return $this; }

    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $m): static { $this->durationMinutes = $m; return $this; }

    public function getTopic(): string { return $this->topic; }
    public function setTopic(string $t): static { $this->topic = $t; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }

    public function getProposedTimes(): ?array { return $this->proposedTimes; }
    public function setProposedTimes(?array $a): static { $this->proposedTimes = $a; return $this; }

    public function getMeetingUrl(): ?string { return $this->meetingUrl; }
    public function setMeetingUrl(?string $u): static { $this->meetingUrl = $u; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->id !== null ? (int) $this->id : null,
            'student_code' => $this->student?->getCode(),
            'student_name' => $this->student?->getName(),
            'mentor_code' => $this->mentor?->getCode(),
            'mentor_name' => $this->mentor?->getName(),
            'event_id' => $this->event?->getId() !== null ? (int) $this->event->getId() : null,
            'start_at' => $this->startAt?->format('c'),
            'duration_minutes' => $this->durationMinutes,
            'topic' => $this->topic,
            'notes' => $this->notes,
            'status' => $this->status,
            'proposed_times' => $this->proposedTimes,
            'meeting_url' => $this->meetingUrl,
            'created_at' => $this->createdAt?->format('c'),
        ];
    }
}
