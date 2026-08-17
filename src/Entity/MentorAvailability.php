<?php

namespace App\Entity;

use App\Repository\MentorAvailabilityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F8 — Disponibilidad horaria de un mentor (cuándo puede dar clases 1:1).
 * dayOfWeek: 1=Lunes .. 7=Domingo (ISO).
 * startTime/endTime: "HH:MM" tipo TIME de DB.
 */
#[ORM\Entity(repositoryClass: MentorAvailabilityRepository::class)]
#[ORM\Table(name: 'mentor_availability')]
#[ORM\Index(name: 'idx_ma_mentor_day', columns: ['mentor_id', 'day_of_week'])]
class MentorAvailability
{
    public const STATUS_OPEN    = 'open';
    public const STATUS_BLOCKED = 'blocked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'mentor_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $mentor = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $dayOfWeek = 1; // 1=Lun .. 7=Dom

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }

    public function getMentor(): ?User { return $this->mentor; }
    public function setMentor(?User $u): static { $this->mentor = $u; return $this; }

    public function getDayOfWeek(): int { return $this->dayOfWeek; }
    public function setDayOfWeek(int $d): static { $this->dayOfWeek = $d; return $this; }

    public function getStartTime(): ?\DateTimeImmutable { return $this->startTime; }
    public function setStartTime(?\DateTimeImmutable $t): static { $this->startTime = $t; return $this; }

    public function getEndTime(): ?\DateTimeImmutable { return $this->endTime; }
    public function setEndTime(?\DateTimeImmutable $t): static { $this->endTime = $t; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->id !== null ? (int) $this->id : null,
            'mentor_code' => $this->mentor?->getCode(),
            'mentor_name' => $this->mentor?->getName(),
            'day_of_week' => $this->dayOfWeek,
            'day_label' => ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][$this->dayOfWeek] ?? '',
            'start_time' => $this->startTime?->format('H:i'),
            'end_time'   => $this->endTime?->format('H:i'),
            'status' => $this->status,
        ];
    }
}
