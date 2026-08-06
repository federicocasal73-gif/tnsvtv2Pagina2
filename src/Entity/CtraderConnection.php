<?php

namespace App\Entity;

use App\Repository\CtraderConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CtraderConnectionRepository::class)]
#[ORM\Table(name: 'ctrader_connections')]
#[ORM\Index(name: 'idx_ct_user', columns: ['user_id'])]
class CtraderConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $ctraderAccountId = '';

    #[ORM\Column(length: 500)]
    private string $accessToken = '';

    #[ORM\Column(length: 500)]
    private string $refreshToken = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getCtraderAccountId(): string { return $this->ctraderAccountId; }
    public function setCtraderAccountId(string $id): static { $this->ctraderAccountId = $id; return $this; }

    public function getAccessToken(): string { return $this->accessToken; }
    public function setAccessToken(string $token): static { $this->accessToken = $token; return $this; }

    public function getRefreshToken(): string { return $this->refreshToken; }
    public function setRefreshToken(string $token): static { $this->refreshToken = $token; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $v): static { $this->expiresAt = $v; return $this; }

    public function isTokenExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
