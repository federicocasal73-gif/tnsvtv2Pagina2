<?php

namespace App\Entity;

use App\Repository\WalletTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historial de movimientos del wallet virtual de un user.
 * Tipos: deposit (admin/MP/Binance credita), entry_fee (torneo), payout (premio),
 *        refund (devolucion), withdraw (retiro pendiente).
 */
#[ORM\Entity(repositoryClass: WalletTransactionRepository::class)]
#[ORM\Table(name: 'wallet_transactions')]
#[ORM\Index(name: 'idx_wtx_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_wtx_tournament', columns: ['ref_tournament_id'])]
#[ORM\Index(name: 'idx_wtx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_wtx_created', columns: ['created_at'])]
class WalletTransaction
{
    public const TYPE_DEPOSIT   = 'deposit';     // user recibe USD (admin/MP/Binance)
    public const TYPE_ENTRY_FEE = 'entry_fee';   // user paga para entrar a torneo
    public const TYPE_PAYOUT    = 'payout';      // user recibe premio de torneo
    public const TYPE_REFUND    = 'refund';      // devolucion (torneo cancelado)
    public const TYPE_WITHDRAW  = 'withdraw';    // user retira USD (pendiente)
    public const TYPE_DUEL_ENTRY = 'duel_entry';  // entrada a duelo 1v1
    public const TYPE_DUEL_WIN   = 'duel_win';    // premio de duelo 1v1
    public const TYPE_DUEL_REFUND = 'duel_refund'; // devolucion de duelo cancelado/empate

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED  = 'confirmed';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_REFUNDED   = 'refunded';

    public const METHOD_MANUAL_MP     = 'manual_mp';
    public const METHOD_MANUAL_BINANCE = 'manual_binance';
    public const METHOD_MANUAL_CRYPTO  = 'manual_crypto';
    public const METHOD_AUTO_MP        = 'auto_mp';
    public const METHOD_AUTO_BINANCE   = 'auto_binance';
    public const METHOD_AUTO_CRYPTO    = 'auto_crypto';
    public const METHOD_GIFT           = 'gift';
    public const METHOD_OTHER          = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'walletTransactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_DEPOSIT;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 8)]
    private string $currency = 'USD';

    #[ORM\ManyToOne(targetEntity: Tournament::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tournament $refTournament = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $refPaymentId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $refPaymentMethod = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): self { $this->type = $t; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $a): self { $this->amount = $a; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }
    public function getRefTournament(): ?Tournament { return $this->refTournament; }
    public function setRefTournament(?Tournament $t): self { $this->refTournament = $t; return $this; }
    public function getRefPaymentId(): ?string { return $this->refPaymentId; }
    public function setRefPaymentId(?string $s): self { $this->refPaymentId = $s; return $this; }
    public function getRefPaymentMethod(): ?string { return $this->refPaymentMethod; }
    public function setRefPaymentMethod(?string $s): self { $this->refPaymentMethod = $s; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): self { $this->createdAt = $d; return $this; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeImmutable $d): self { $this->confirmedAt = $d; return $this; }
    public function getConfirmedBy(): ?User { return $this->confirmedBy; }
    public function setConfirmedBy(?User $u): self { $this->confirmedBy = $u; return $this; }

    public function isCredit(): bool
    {
        return in_array($this->type, [self::TYPE_DEPOSIT, self::TYPE_PAYOUT, self::TYPE_REFUND, self::TYPE_DUEL_WIN, self::TYPE_DUEL_REFUND], true);
    }

    public function getFormattedAmount(): string
    {
        $prefix = $this->isCredit() ? '+' : '-';
        return $prefix . '$' . number_format(abs((float) $this->amount), 2);
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_DEPOSIT   => 'Deposito',
            self::TYPE_ENTRY_FEE => 'Entry Fee',
            self::TYPE_PAYOUT    => 'Premio',
            self::TYPE_REFUND    => 'Devolucion',
            self::TYPE_WITHDRAW  => 'Retiro',
            default => $this->type,
        };
    }
}
