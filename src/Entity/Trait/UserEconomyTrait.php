<?php

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Economía triple-currency del usuario (USD wallet, coins, reputación).
 * Extraído de User para reducir el god-entity.
 */
trait UserEconomyTrait
{
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $walletBalance = '0.00';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 3])]
    private int $maxAccounts = 3;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $coins = 0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    private float $reputation = 0.0;

    public function getWalletBalance(): string { return $this->walletBalance; }
    public function setWalletBalance(string $b): static { $this->walletBalance = $b; return $this; }

    public function getMaxAccounts(): int { return $this->maxAccounts; }
    public function setMaxAccounts(int $v): static { $this->maxAccounts = $v; return $this; }

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
}