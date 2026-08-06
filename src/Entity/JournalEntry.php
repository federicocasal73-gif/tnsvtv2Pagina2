<?php

namespace App\Entity;

use App\Repository\JournalEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entrada del diario de trading del usuario.
 *
 * Separada de Trade (que es server-authoritative en torneos).
 * Esta entidad es la que el usuario edita manualmente en su journal offline-first.
 *
 * Sync via App\Controller\Api\SyncController (LWW sobre updated_at).
 */
#[ORM\Entity(repositoryClass: JournalEntryRepository::class)]
#[ORM\Table(name: 'journal_entries')]
#[ORM\Index(name: 'idx_je_user', columns: ['user_code'])]
#[ORM\Index(name: 'idx_je_updated', columns: ['updated_at'])]
class JournalEntry
{
    public const RESULT_WIN  = 'WIN';
    public const RESULT_LOSS = 'LOSS';
    public const RESULT_BE   = 'BE'; // break-even
    public const RESULT_OPEN = 'OPEN';

    public const DIRECTION_BUY  = 'BUY';
    public const DIRECTION_SELL = 'SELL';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(length: 32)]
    private string $userCode = '';

    #[ORM\Column(length: 16)]
    private string $asset = '';

    #[ORM\Column(length: 8)]
    private string $direction = self::DIRECTION_BUY;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $entry = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $sl = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $tp = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $pnl = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $ratio = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $photos = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tags = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->date = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getUserCode(): string { return $this->userCode; }
    public function setUserCode(string $c): self { $this->userCode = $c; return $this; }
    public function getAsset(): string { return $this->asset; }
    public function setAsset(string $a): self { $this->asset = strtoupper($a); return $this; }
    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $d): self { $this->direction = $d; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable|string $d): self {
        if (is_string($d)) { $d = new \DateTimeImmutable($d); }
        $this->date = $d; return $this;
    }
    public function getEntry(): ?string { return $this->entry; }
    public function setEntry(?string $e): self { $this->entry = $e; return $this; }
    public function getSl(): ?string { return $this->sl; }
    public function setSl(?string $s): self { $this->sl = $s; return $this; }
    public function getTp(): ?string { return $this->tp; }
    public function setTp(?string $t): self { $this->tp = $t; return $this; }
    public function getResult(): ?string { return $this->result; }
    public function setResult(?string $r): self { $this->result = $r; return $this; }
    public function getPnl(): ?string { return $this->pnl; }
    public function setPnl($p): self {
        if ($p === null || $p === '') { $this->pnl = null; return $this; }
        $this->pnl = (string) $p; return $this;
    }
    public function getRatio(): ?string { return $this->ratio; }
    public function setRatio(?string $r): self { $this->ratio = $r; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function getPhotos(): ?string { return $this->photos; }
    public function setPhotos(?string $p): self { $this->photos = $p; return $this; }
    public function getTags(): ?string { return $this->tags; }
    public function setTags(?string $t): self { $this->tags = $t; return $this; }
    public function getAccountId(): ?string { return $this->accountId; }
    public function setAccountId($id): self {
        if ($id === null || $id === '') { $this->accountId = null; return $this; }
        $this->accountId = (string) $id; return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
