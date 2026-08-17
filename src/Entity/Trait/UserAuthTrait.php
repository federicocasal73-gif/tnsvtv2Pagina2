<?php

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Campos de autenticación e identidad del usuario.
 * Extraído de User para reducir el god-entity (~285 líneas → ~90).
 */
trait UserAuthTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLogin = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $currentRefreshTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refreshTokenRotatedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }

    public function getLastLogin(): ?\DateTimeImmutable { return $this->lastLogin; }
    public function setLastLogin(?\DateTimeImmutable $lastLogin): static { $this->lastLogin = $lastLogin; return $this; }

    public function getLastActivityAt(): ?\DateTimeImmutable { return $this->lastActivityAt; }
    public function setLastActivityAt(?\DateTimeImmutable $lastActivityAt): static { $this->lastActivityAt = $lastActivityAt; return $this; }

    public function isOnline(): bool
    {
        if (!$this->lastActivityAt) return false;
        return $this->lastActivityAt > new \DateTimeImmutable('-2 minutes');
    }

    public function getRoles(): array { return $this->roles; }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $password): static { $this->password = $password; return $this; }

    public function getCurrentRefreshTokenHash(): ?string { return $this->currentRefreshTokenHash; }
    public function setCurrentRefreshTokenHash(?string $h): static { $this->currentRefreshTokenHash = $h; return $this; }

    public function getRefreshTokenRotatedAt(): ?\DateTimeImmutable { return $this->refreshTokenRotatedAt; }
    public function setRefreshTokenRotatedAt(?\DateTimeImmutable $d): static { $this->refreshTokenRotatedAt = $d; return $this; }

    public function getIsAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getUserIdentifier(): string { return $this->code ?? ''; }
    public function eraseCredentials(): void {}
}