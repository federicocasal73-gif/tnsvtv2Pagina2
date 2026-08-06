<?php

namespace App\Entity;

use App\Repository\PropFirmAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PropFirmAccountRepository::class)]
#[ORM\Table(name: 'prop_firm_accounts')]
#[ORM\Index(name: 'idx_pfa_user', columns: ['user_id'])]
class PropFirmAccount
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_VIOLATED = 'violated';
    public const STATUS_PASSED   = 'passed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TradingAccount $tradingAccount = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PropFirm $propFirm = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $accountSize = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $peakBalance = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $currentBalance = '0.00';

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getTradingAccount(): ?TradingAccount { return $this->tradingAccount; }
    public function setTradingAccount(?TradingAccount $a): static { $this->tradingAccount = $a; return $this; }

    public function getPropFirm(): ?PropFirm { return $this->propFirm; }
    public function setPropFirm(?PropFirm $pf): static { $this->propFirm = $pf; return $this; }

    public function getAccountSize(): string { return $this->accountSize; }
    public function setAccountSize(string|float $v): static { $this->accountSize = (string) $v; return $this; }

    public function getPeakBalance(): string { return $this->peakBalance; }
    public function setPeakBalance(string|float $v): static { $this->peakBalance = (string) $v; return $this; }

    public function getCurrentBalance(): string { return $this->currentBalance; }
    public function setCurrentBalance(string|float $v): static { $this->currentBalance = (string) $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static
    {
        $valid = [self::STATUS_ACTIVE, self::STATUS_VIOLATED, self::STATUS_PASSED];
        if (in_array($status, $valid, true)) {
            $this->status = $status;
        }
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $v): static
    {
        $this->startedAt = $v ?? new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getDrawdownPct(): float
    {
        $peak = (float) $this->peakBalance;
        $current = (float) $this->currentBalance;
        $size = (float) $this->accountSize;
        if ($peak <= 0 || $size <= 0) return 0;
        return round(($peak - $current) / $size * 100, 2);
    }

    public function getProfitPct(): float
    {
        $current = (float) $this->currentBalance;
        $size = (float) $this->accountSize;
        if ($size <= 0) return 0;
        return round(($current - $size) / $size * 100, 2);
    }
}
