<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
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

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $walletBalance = '0.00';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 3])]
    private int $maxAccounts = 3;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $diarySetupToken = null;

    #[ORM\Column(length: 48, nullable: true)]
    private ?string $diarySetupIv = null;

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'chime'])]
    private ?string $notificationSound = 'chime';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vipUntil = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $coins = 0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    private float $reputation = 0.0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dailyLogin = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shopEquipped = null;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WalletTransaction::class)]
    private Collection $walletTransactions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: TournamentEntry::class)]
    private Collection $tournamentEntries;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: DiaryEntry::class)]
    private Collection $diaryEntries;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Connection::class)]
    private Collection $connections;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: JournalSetting::class)]
    private ?JournalSetting $journalSetting = null;

    public function __construct()
    {
        $this->walletTransactions = new ArrayCollection();
        $this->tournamentEntries = new ArrayCollection();
        $this->diaryEntries = new ArrayCollection();
        $this->connections = new ArrayCollection();
    }

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

    public function getWalletBalance(): string { return $this->walletBalance; }
    public function setWalletBalance(string $b): static { $this->walletBalance = $b; return $this; }

    public function getMaxAccounts(): int { return $this->maxAccounts; }
    public function setMaxAccounts(int $v): static { $this->maxAccounts = $v; return $this; }

    public function getDiarySetupToken(): ?string { return $this->diarySetupToken; }
    public function setDiarySetupToken(?string $token): static { $this->diarySetupToken = $token; return $this; }

    public function getDiarySetupIv(): ?string { return $this->diarySetupIv; }
    public function setDiarySetupIv(?string $iv): static { $this->diarySetupIv = $iv; return $this; }

    public function getNotificationSound(): ?string { return $this->notificationSound; }
    public function setNotificationSound(?string $s): static { $this->notificationSound = $s; return $this; }

    public function getVipUntil(): ?\DateTimeImmutable { return $this->vipUntil; }
    public function setVipUntil(?\DateTimeImmutable $vipUntil): static { $this->vipUntil = $vipUntil; return $this; }

    public function isVip(): bool
    {
        return $this->vipUntil !== null && $this->vipUntil > new \DateTimeImmutable();
    }

    // Week 2 - Día 1: Triple-currency economy
    public function getCoins(): int { return $this->coins; }
    public function setCoins(int $v): static { $this->coins = max(0, min(1000000, $v)); return $this; }
    public function addCoins(int $amount): static
    {
        $this->coins = max(0, min(1000000, $this->coins + $amount));
        return $this;
    }
    public function spendCoins(int $amount): bool
    {
        if ($amount < 0 || $this->coins < $amount) return false;
        $this->coins -= $amount;
        return true;
    }

    public function getReputation(): float { return $this->reputation; }
    public function setReputation(float $v): static { $this->reputation = max(0.0, min(100.0, $v)); return $this; }
    public function addReputation(float $amount): static
    {
        $this->reputation = max(0.0, min(100.0, $this->reputation + $amount));
        return $this;
    }

    public function getDailyLogin(): ?array { return $this->dailyLogin; }
    public function setDailyLogin(?array $v): static { $this->dailyLogin = $v; return $this; }

    public function getShopEquipped(): ?array { return $this->shopEquipped; }
    public function setShopEquipped(?array $v): static { $this->shopEquipped = $v; return $this; }

    public function getWalletBalanceFloat(): float { return (float) $this->walletBalance; }
    public function hasBalance(float $min): bool { return $this->getWalletBalanceFloat() >= $min; }
    public function addToWallet(float $amount): static
    {
        $this->walletBalance = number_format($this->getWalletBalanceFloat() + $amount, 2, '.', '');
        return $this;
    }
    public function subtractFromWallet(float $amount): static
    {
        $this->walletBalance = number_format($this->getWalletBalanceFloat() - $amount, 2, '.', '');
        return $this;
    }

    public function getWalletTransactions(): Collection { return $this->walletTransactions; }
    public function getTournamentEntries(): Collection { return $this->tournamentEntries; }
    public function getDiaryEntries(): Collection { return $this->diaryEntries; }
    public function getConnections(): Collection { return $this->connections; }
    public function getJournalSetting(): ?JournalSetting { return $this->journalSetting; }

    public function getAvatarUrl(): ?string
    {
        $code = $this->code;
        if (!$code) return null;
        $avatarDir = dirname(__DIR__, 2) . '/public/uploads/avatars';
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            if (is_file("$avatarDir/$code.$ext")) {
                return "/uploads/avatars/$code.$ext";
            }
        }
        return null;
    }

    public function getAvatarColor(): ?string
    {
        // ⛧ FIX BUG-5: Color determinístico basado en el código del usuario
        // (siempre retorna null antes → todos los avatares eran violeta)
        $colors = [
            '#9353ff', '#34c759', '#ffb300', '#3b9eff', '#ff6b6b',
            '#c327fb', '#00bfa5', '#ff4081', '#7c4dff', '#69f0ae',
        ];
        $code = $this->code ?? 'X';
        $idx = abs(crc32($code)) % count($colors);
        return $colors[$idx];
    }

    public function getIsAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getUserIdentifier(): string { return $this->code ?? ''; }
    public function eraseCredentials(): void {}
}

