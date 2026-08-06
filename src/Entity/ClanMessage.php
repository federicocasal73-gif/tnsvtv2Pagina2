<?php

namespace App\Entity;

use App\Repository\ClanMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mensaje del chat del clan.
 */
#[ORM\Entity(repositoryClass: ClanMessageRepository::class)]
#[ORM\Table(name: 'clan_messages')]
#[ORM\Index(columns: ['clan_id', 'created_at'], name: 'idx_cm_clan_time')]
class ClanMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Clan::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clan $clan = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $sender = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[ORM\Column(length: 20)]
    private string $type = 'text'; // text, system, achievement, objective

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClan(): ?Clan { return $this->clan; }
    public function setClan(?Clan $c): self { $this->clan = $c; return $this; }
    public function getSender(): ?User { return $this->sender; }
    public function setSender(?User $u): self { $this->sender = $u; return $this; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $c): self { $this->content = $c; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): self { $this->type = $t; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}