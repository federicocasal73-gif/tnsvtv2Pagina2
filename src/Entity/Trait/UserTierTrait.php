<?php

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tier, VIP y preferencias de usuario (diary, sonido, shop).
 * Extraído de User para reducir el god-entity.
 */
trait UserTierTrait
{
    public const TIER_INITIATE = 'INITIATE';
    public const TIER_ASPIRANT = 'ASPIRANT';
    public const TIER_1 = 'TIER_1';
    public const TIER_2 = 'TIER_2';
    public const TIER_3_ZENITH = 'TIER_3_ZENITH';
    public const TIER_MASTER = 'MASTER';

    public const TIERS = [
        'INITIATE',
        'ASPIRANT',
        'TIER_1',
        'TIER_2',
        'TIER_3_ZENITH',
        'MASTER',
    ];

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, options: ['default' => 'INITIATE'])]
    private ?string $tier = 'INITIATE';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vipUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $diarySetupToken = null;

    #[ORM\Column(length: 48, nullable: true)]
    private ?string $diarySetupIv = null;

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'chime'])]
    private ?string $notificationSound = 'chime';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dailyLogin = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shopEquipped = null;

    public function getTier(): string { return $this->tier ?? static::TIER_INITIATE; }
    public function setTier(string $tier): static
    {
        if (!in_array($tier, static::TIERS, true)) {
            throw new \InvalidArgumentException("Invalid tier: $tier");
        }
        $this->tier = $tier;
        return $this;
    }

    public function getVipUntil(): ?\DateTimeImmutable { return $this->vipUntil; }
    public function setVipUntil(?\DateTimeImmutable $vipUntil): static { $this->vipUntil = $vipUntil; return $this; }

    public function isVip(): bool
    {
        return $this->vipUntil !== null && $this->vipUntil > new \DateTimeImmutable();
    }

    public function getDiarySetupToken(): ?string { return $this->diarySetupToken; }
    public function setDiarySetupToken(?string $token): static { $this->diarySetupToken = $token; return $this; }

    public function getDiarySetupIv(): ?string { return $this->diarySetupIv; }
    public function setDiarySetupIv(?string $iv): static { $this->diarySetupIv = $iv; return $this; }

    public function getNotificationSound(): ?string { return $this->notificationSound; }
    public function setNotificationSound(?string $s): static { $this->notificationSound = $s; return $this; }

    public function getDailyLogin(): ?array { return $this->dailyLogin; }
    public function setDailyLogin(?array $v): static { $this->dailyLogin = $v; return $this; }

    public function getShopEquipped(): ?array { return $this->shopEquipped; }
    public function setShopEquipped(?array $v): static { $this->shopEquipped = $v; return $this; }
}