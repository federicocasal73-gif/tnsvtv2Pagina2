<?php

namespace App\Entity;

use App\Repository\MarketCandleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketCandleRepository::class)]
#[ORM\Table(name: 'market_candle')]
#[ORM\Index(name: 'idx_market_symbol_exchange_interval_ts', columns: ['symbol', 'exchange', 'interval', 'timestamp'])]
class MarketCandle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $symbol;

    #[ORM\Column(length: 20)]
    private string $exchange;

    #[ORM\Column(length: 10)]
    private string $interval;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $open;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $high;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $low;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $close;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private string $volume;

    #[ORM\Column]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
    public function getSymbol(): string { return $this->symbol; }
    public function setSymbol(string $symbol): self { $this->symbol = $symbol; return $this; }
    public function getExchange(): string { return $this->exchange; }
    public function setExchange(string $exchange): self { $this->exchange = $exchange; return $this; }
    public function getInterval(): string { return $this->interval; }
    public function setInterval(string $interval): self { $this->interval = $interval; return $this; }
    public function getOpen(): string { return $this->open; }
    public function setOpen(string $open): self { $this->open = $open; return $this; }
    public function getHigh(): string { return $this->high; }
    public function setHigh(string $high): self { $this->high = $high; return $this; }
    public function getLow(): string { return $this->low; }
    public function setLow(string $low): self { $this->low = $low; return $this; }
    public function getClose(): string { return $this->close; }
    public function setClose(string $close): self { $this->close = $close; return $this; }
    public function getVolume(): string { return $this->volume; }
    public function setVolume(string $volume): self { $this->volume = $volume; return $this; }
    public function getTimestamp(): \DateTimeImmutable { return $this->timestamp; }
    public function setTimestamp(\DateTimeImmutable $timestamp): self { $this->timestamp = $timestamp; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function toArray(): array
    {
        return [
            't' => $this->timestamp->getTimestamp() * 1000,
            'o' => (float) $this->open,
            'h' => (float) $this->high,
            'l' => (float) $this->low,
            'c' => (float) $this->close,
            'v' => (float) $this->volume,
        ];
    }
}