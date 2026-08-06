<?php

namespace App\Entity;

use App\Repository\JournalPermissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JournalPermissionRepository::class)]
#[ORM\Table(name: 'journal_permissions')]
#[ORM\UniqueConstraint(name: 'uq_journal_perm', columns: ['grantor_id', 'grantee_id'])]
class JournalPermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $grantor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $grantee = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $canViewStats = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $canViewTrades = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canViewNotes = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canViewComments = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canDownloadCsv = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canViewRealtime = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getGrantor(): ?User { return $this->grantor; }
    public function setGrantor(?User $grantor): static { $this->grantor = $grantor; return $this; }
    public function getGrantee(): ?User { return $this->grantee; }
    public function setGrantee(?User $grantee): static { $this->grantee = $grantee; return $this; }
    public function canViewStats(): bool { return $this->canViewStats; }
    public function setCanViewStats(bool $v): static { $this->canViewStats = $v; return $this; }
    public function canViewTrades(): bool { return $this->canViewTrades; }
    public function setCanViewTrades(bool $v): static { $this->canViewTrades = $v; return $this; }
    public function canViewNotes(): bool { return $this->canViewNotes; }
    public function setCanViewNotes(bool $v): static { $this->canViewNotes = $v; return $this; }
    public function canViewComments(): bool { return $this->canViewComments; }
    public function setCanViewComments(bool $v): static { $this->canViewComments = $v; return $this; }
    public function canDownloadCsv(): bool { return $this->canDownloadCsv; }
    public function setCanDownloadCsv(bool $v): static { $this->canDownloadCsv = $v; return $this; }
    public function canViewRealtime(): bool { return $this->canViewRealtime; }
    public function setCanViewRealtime(bool $v): static { $this->canViewRealtime = $v; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}
