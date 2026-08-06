<?php

namespace App\Entity;

use App\Repository\PropFirmAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PropFirmAlertRepository::class)]
#[ORM\Table(name: 'prop_firm_alerts')]
#[ORM\Index(name: 'idx_pfalert_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_pfalert_account', columns: ['prop_firm_account_id'])]
class PropFirmAlert
{
    public const TYPE_DRAWDOWN_WARNING    = 'drawdown_warning';
    public const TYPE_DRAWDOWN_CRITICAL   = 'drawdown_critical';
    public const TYPE_DAILY_LOSS_WARNING  = 'daily_loss_warning';
    public const TYPE_PROFIT_ACHIEVED     = 'profit_target_achieved';
    public const TYPE_VIOLATION           = 'violation';

    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_DANGER  = 'danger';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PropFirmAccount $propFirmAccount = null;

    #[ORM\Column(length: 30)]
    private string $alertType = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(length: 10, options: ['default' => 'info'])]
    private string $severity = self::SEVERITY_INFO;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getPropFirmAccount(): ?PropFirmAccount { return $this->propFirmAccount; }
    public function setPropFirmAccount(?PropFirmAccount $a): static { $this->propFirmAccount = $a; return $this; }

    public function getAlertType(): string { return $this->alertType; }
    public function setAlertType(string $type): static { $this->alertType = $type; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $msg): static { $this->message = $msg; return $this; }

    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $severity): static { $this->severity = $severity; return $this; }

    public function isRead(): bool { return $this->isRead; }
    public function setIsRead(bool $v): static { $this->isRead = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
