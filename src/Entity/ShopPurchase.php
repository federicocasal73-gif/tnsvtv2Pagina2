<?php

namespace App\Entity;

use App\Repository\ShopPurchaseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopPurchaseRepository::class)]
#[ORM\Table(name: 'shop_purchases')]
class ShopPurchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $itemId = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $coinsSpent = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $xpSpent = null;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $purchasedAt;

    public function __construct()
    {
        $this->purchasedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getItemId(): string { return $this->itemId; }
    public function setItemId(string $v): static { $this->itemId = $v; return $this; }
    public function getCoinsSpent(): int { return $this->coinsSpent; }
    public function setCoinsSpent(int $v): static { $this->coinsSpent = $v; return $this; }
    public function getXpSpent(): ?int { return $this->xpSpent; }
    public function setXpSpent(?int $v): static { $this->xpSpent = $v; return $this; }
    public function getPurchasedAt(): \DateTimeImmutable { return $this->purchasedAt; }
}
