<?php

namespace App\Entity;

use App\Repository\MonitorEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitorEventRepository::class)]
#[ORM\Table(name: 'monitor_event')]
#[ORM\Index(name: 'idx_monitor_user_created', columns: ['user_code', 'created_at'])]
#[ORM\Index(name: 'idx_monitor_level_created', columns: ['level', 'created_at'])]
class MonitorEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $level;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $stack = null;

    #[ORM\Column(length: 64)]
    private string $source;

    #[ORM\Column(length: 32)]
    private string $userCode;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
    public function getLevel(): string { return $this->level; }
    public function setLevel(string $level): self { $this->level = $level; return $this; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }
    public function getStack(): ?string { return $this->stack; }
    public function setStack(?string $stack): self { $this->stack = $stack; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function getUserCode(): string { return $this->userCode; }
    public function setUserCode(string $userCode): self { $this->userCode = $userCode; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self { $this->url = $url; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
}