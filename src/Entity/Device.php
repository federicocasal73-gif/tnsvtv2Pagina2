<?php

namespace App\Entity;

use App\Repository\DeviceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'devices')]
#[ORM\UniqueConstraint(name: 'uniq_fcm_token', columns: ['fcm_token'])]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 512)]
    private ?string $fcmToken = null;

    #[ORM\Column(length: 32, options: ['default' => 'android'])]
    private string $platform = 'android';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $deviceModel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct()
    {
        $this->registeredAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getFcmToken(): ?string { return $this->fcmToken; }
    public function setFcmToken(string $fcmToken): static { $this->fcmToken = $fcmToken; return $this; }

    public function getPlatform(): string { return $this->platform; }
    public function setPlatform(string $platform): static { $this->platform = $platform; return $this; }

    public function getDeviceModel(): ?string { return $this->deviceModel; }
    public function setDeviceModel(?string $deviceModel): static { $this->deviceModel = $deviceModel; return $this; }

    public function getRegisteredAt(): ?\DateTimeImmutable { return $this->registeredAt; }
    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function touch(): static { $this->lastSeenAt = new \DateTimeImmutable(); return $this; }
}
